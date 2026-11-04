<?php

namespace Tests\Feature\EndToEnd;

use App\Domain\Sefaz\SefazClient;
use App\Jobs\DispatchSyncNfeJob;
use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\Nsu;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fluxo fim-a-fim da recepção de NF-e — do cadastro à consulta.
 *
 * Percorre o caminho COMPLETO de produção, apenas com a SEFAZ mockada:
 *
 *   1. cria tenant, unidade e certificado (fixture e-CNPJ real);
 *   2. "agenda" a sincronização pelo mesmo job do scheduler;
 *   3. a SEFAZ (mock) devolve um resNFe (resumo);
 *   4. o sistema dá ciência da operação e reconsulta (fluxo resumo→completo);
 *   5. a NF-e é importada com XML, itens e cursor NSU atualizado;
 *   6. a NF-e aparece na API (lista e detalhe) com XML por URL assinada.
 *
 * O objetivo é provar que as peças (jobs, guard, service, importer, API)
 * funcionam juntas — e não apenas isoladas, como nos testes de unidade.
 */
class SyncFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Unidade $unidade;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesSeeder::class);

        // Disco privado isolado: XMLs, PFX e exports vão para um fake.
        Storage::fake('local');

        // Rate limiter global alto: o foco do teste é o fluxo, não o freio
        // (que tem testes dedicados). Evita esbarrar no teto de 30/min.
        config(['nfegov.sefaz.rate_per_minute' => 1000]);

        $this->tenant = Tenant::factory()->create();

        // A unidade usa o MESMO CNPJ da fixture, requisito do upload do PFX.
        $this->unidade = Unidade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'cnpj' => '11222333000181',
            'uf' => 'DF',
            'tp_amb' => 2,
            'ativo' => true,
        ]);

        $this->operador = User::factory()->comTenant($this->tenant)->create();
        $this->operador->assignRole('operador');
    }

    /**
     * Envia o certificado real (fixture) pela rota autenticada.
     *
     * Exercita o caminho de produção do upload (extração de metadados,
     * cifragem, gravação do PFX) em vez de fabricar o registro na mão.
     */
    private function enviarCertificado(): void
    {
        $admin = User::factory()->comTenant($this->tenant)->create();
        $admin->assignRole('admin-tenant');
        $admin->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->postJson("/api/unidades/{$this->unidade->id}/certificado", [
                'arquivo' => new UploadedFile(
                    base_path('tests/Fixtures/cert_e_cnpj.pfx'),
                    'certificado.pfx',
                    'application/x-pkcs12',
                    null,
                    true
                ),
                'senha' => 'senha-pfx',
            ])
            ->assertCreated();

        $this->assertTrue(
            Certificate::where('unidade_id', $this->unidade->id)->where('ativo', true)->exists(),
            'O certificado deveria estar ativo após o upload.'
        );
    }

    private function docZip(string $xml): string
    {
        return base64_encode(gzencode($xml));
    }

    private function envelope(array $docs, int $ultNsu, int $maxNsu): string
    {
        $lote = '';
        foreach ($docs as $doc) {
            $lote .= "<docZip NSU=\"{$doc['nsu']}\" schema=\"{$doc['schema']}\">{$doc['conteudo']}</docZip>";
        }

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
  <tpAmb>2</tpAmb>
  <verAplic>1.0.0</verAplic>
  <cStat>137</cStat>
  <xMotivo>Documento localizado</xMotivo>
  <ultNSU>{$ultNsu}</ultNSU>
  <maxNSU>{$maxNsu}</maxNSU>
  <loteDistDFeInt>{$lote}</loteDistDFeInt>
</retDistDFeInt>
XML;
    }

    private function xmlResNFe(string $chave): string
    {
        return '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.$chave.'</chNFe>'
            .'<CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ Materiais.</xNome>'
            .'<vNF>1500.00</vNF><dhEmi>2026-10-20T10:00:00-03:00</dhEmi></resNFe>';
    }

    private function xmlNfeProc(string $chave): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$chave.'">'
            .'<ide><mod>55</mod><serie>1</serie><nNF>200</nNF><dhEmi>2026-10-20T10:00:00-03:00</dhEmi>'
            .'<natOp>Venda de mercadoria</natOp></ide>'
            .'<emit><CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ Materiais.</xNome></emit>'
            .'<dest><CNPJ>00000000000191</CNPJ></dest>'
            .'<det nItem="1"><prod><xProd>Caneta esferográfica azul</xProd><CFOP>5102</CFOP>'
            .'<uCom>UN</uCom><qCom>100</qCom><vUnCom>1.50</vUnCom><vProd>150.00</vProd></prod></det>'
            .'<total><ICMSTot><vNF>1500.00</vNF></ICMSTot></total>'
            .'</infNFe></NFe></nfeProc>';
    }

    public function test_fluxo_completo_de_recepcao_de_nfe(): void
    {
        $this->enviarCertificado();

        $chave = str_repeat('4', 44);

        $client = \Mockery::mock(SefazClient::class);

        // 1ª rodada: a SEFAZ devolve apenas o RESUMO (resNFe).
        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 0)
            ->once()
            ->andReturn($this->envelope([
                ['nsu' => 1, 'schema' => 'resNFe', 'conteudo' => $this->docZip($this->xmlResNFe($chave))],
            ], 1, 1));

        // O sistema dá CIÊNCIA da operação automaticamente.
        // Compara por id: o job recarrega a Unidade, então a instância recebida
        // não é a mesma que guardamos no setUp.
        $client->shouldReceive('manifestarCiencia')
            ->once()
            ->withArgs(fn ($u, $c) => $u->id === $this->unidade->id && $c === $chave)
            ->andReturn(true);

        // E reconsulta o download, obtendo o XML COMPLETO.
        $client->shouldReceive('baixarPorChave')
            ->once()
            ->withArgs(fn ($u, $c) => $u->id === $this->unidade->id && $c === $chave)
            ->andReturn($this->xmlNfeProc($chave));

        app()->instance(SefazClient::class, $client);

        // "Agenda" a sincronização pelo MESMO job que o scheduler dispara.
        DispatchSyncNfeJob::dispatchSync();

        // --- Verificações no banco (fora do escopo do tenant) ---------------

        $nfe = Nfe::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($nfe, 'A NF-e deveria ter sido importada.');
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
        $this->assertNotNull($nfe->xml_path, 'O XML completo deveria estar persistido.');
        $this->assertTrue(Storage::disk('local')->exists($nfe->xml_path));
        $this->assertSame('Fornecedor XYZ Materiais.', $nfe->emitente_nome);
        $this->assertSame('99888777000166', $nfe->emitente_cnpj);

        // O cursor NSU avançou até o documento consumido.
        $nsu = Nsu::withoutGlobalScopes()->where('unidade_id', $this->unidade->id)->first();
        $this->assertNotNull($nsu);
        $this->assertSame(1, $nsu->ultimo_nsu);

        // A execução ficou registrada como sucesso.
        $run = SyncRun::withoutGlobalScopes()->where('unidade_id', $this->unidade->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(SyncRun::STATUS_SUCESSO, $run->status);
        $this->assertSame(1, $run->docs_count);

        // --- Verificações pela API -----------------------------------------

        $lista = $this->actingAs($this->operador)->getJson('/api/nfes');
        $lista->assertOk();
        $this->assertSame(1, $lista->json('meta.total'));
        $this->assertSame($chave, $lista->json('data.0.chave'));

        $detalhe = $this->actingAs($this->operador)->getJson('/api/nfes/'.$chave);
        $detalhe->assertOk();
        $this->assertSame(Nfe::ESTADO_COMPLETO, $detalhe->json('data.estado'));
        $this->assertNotEmpty($detalhe->json('data.itens'));

        // O XML completo é servido por URL assinada e temporária.
        $url = $detalhe->json('data.xml_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', (string) $url);
        $this->get($url)->assertOk();
    }
}
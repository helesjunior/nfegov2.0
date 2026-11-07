<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\DistDfeService;
use App\Domain\Sefaz\SefazClient;
use App\Models\Nfe;
use App\Models\Nsu;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ciclo do distDFe: consulta incremental, decodificação, importação e
 * o freio anti-bloqueio da SEFAZ.
 *
 * O acesso à SEFAZ é abstraído por `SefazClient`, permitindo testar toda a
 * orquestração sem tocar o webservice real.
 */
class DistDfeTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id, 'tp_amb' => 2]);
    }

    private function docZip(string $xml): string
    {
        return base64_encode(gzencode($xml));
    }

    private function envelope(array $docs, int $ultNsu, int $maxNsu, string $cStat = '137'): string
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
  <cStat>{$cStat}</cStat>
  <xMotivo>Consumo indevido</xMotivo>
  <ultNSU>{$ultNsu}</ultNSU>
  <maxNSU>{$maxNsu}</maxNSU>
  <loteDistDFeInt>{$lote}</loteDistDFeInt>
</retDistDFeInt>
XML;
    }

    private function xmlNfeProc(string $chave): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$chave.'">'
            .'<ide><mod>55</mod><serie>1</serie><nNF>123</nNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ</xNome></emit>'
            .'<det nItem="1"><prod><xProd>Item A</xProd><qCom>1</qCom><vProd>10.00</vProd></prod></det>'
            .'<total><ICMSTot><vNF>10.00</vNF></ICMSTot></total>'
            .'</infNFe></NFe></nfeProc>';
    }

    private function xmlResNFe(string $chave): string
    {
        return '<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.$chave.'</chNFe>'
            .'<CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ</xNome>'
            .'<vNF>10.00</vNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></resNFe>';
    }

    public function test_processa_resnfe_dispara_ciencia_e_grava_completo(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $chave = str_repeat('1', 44);
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);

        // 1ª chamada: devolve o resNFe (resumo).
        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 0)
            ->once()
            ->andReturn($this->envelope([
                ['nsu' => 1, 'schema' => 'resNFe', 'conteudo' => $this->docZip($this->xmlResNFe($chave))],
            ], 1, 1));

        // Ciência da operação automática.
        $client->shouldReceive('manifestarCiencia')->once()->with($unidade, $chave)->andReturn(true);

        // Após a ciência, reconsulta o download e obtém o XML completo.
        $client->shouldReceive('baixarPorChave')->once()->with($unidade, $chave)
            ->andReturn($this->xmlNfeProc($chave));

        app()->instance(SefazClient::class, $client);

        app(DistDfeService::class)->sync($unidade, $run);

        $nfe = Nfe::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($nfe);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
        $this->assertNotNull($nfe->xml_path);
    }

    public function test_atualiza_cursor_nsu(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $chave = str_repeat('2', 44);
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->andReturn($this->envelope([
            ['nsu' => 10, 'schema' => 'procNFe', 'conteudo' => $this->docZip($this->xmlNfeProc($chave))],
        ], 10, 10));

        app()->instance(SefazClient::class, $client);

        app(DistDfeService::class)->sync($unidade, $run);

        $nsu = Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->first();
        $this->assertNotNull($nsu);
        $this->assertSame(10, $nsu->ultimo_nsu);
    }

    public function test_para_ao_receber_cstat_656(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        // cStat 656 = consumo indevido: deve parar imediatamente (sem laço infinito).
        $client->shouldReceive('distDFe')->once()->andReturn(
            $this->envelope([], 0, 0, '656')
        );

        app()->instance(SefazClient::class, $client);

        app(DistDfeService::class)->sync($unidade, $run);

        // Não deve ter importado nada nem estourado o laço.
        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }

    public function test_nao_reprocessa_nsu_ja_consumido(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $chave = str_repeat('3', 44);
        Nsu::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'ultimo_nsu' => 50,
            'max_nsu' => 50,
        ]);
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        // Nada novo: maxNSU igual ao já consumido.
        $client->shouldReceive('distDFe')->withArgs(fn ($u, $ultNsu) => $ultNsu === 50)
            ->andReturn($this->envelope([], 50, 50));

        app()->instance(SefazClient::class, $client);

        app(DistDfeService::class)->sync($unidade, $run);

        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
        $this->assertSame(50, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    /**
     * A SEFAZ avança o NSU em lotes VAZIOS (cStat 137) até chegar aos
     * documentos. Parar no primeiro lote vazio impediria o download quando o
     * histórico é grande (ex.: maxNSU=23921, documentos começam depois de
     * centenas de consultas). O laço deve CONTINUAR pelos lotes vazios até
     * maxNSU (ou até o teto de iterações).
     */
    public function test_avanca_por_lotes_vazios_ate_encontrar_documento(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $chave = str_repeat('4', 44);
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);

        // Três lotes VAZIOS avançando o NSU, depois um lote COM documento.
        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 0)
            ->once()
            ->andReturn($this->envelope([], 50, 200));

        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 50)
            ->once()
            ->andReturn($this->envelope([], 100, 200));

        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 100)
            ->once()
            ->andReturn($this->envelope([
                ['nsu' => 150, 'schema' => 'procNFe', 'conteudo' => $this->docZip($this->xmlNfeProc($chave))],
            ], 150, 200));

        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 150)
            ->once()
            ->andReturn($this->envelope([], 200, 200));

        app()->instance(SefazClient::class, $client);

        app(DistDfeService::class)->sync($unidade, $run);

        // O documento lá no NSU 150 foi baixado.
        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());
        $this->assertSame(200, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    /**
     * Regressão: o teto de documentos por execução cortava o lote no meio e o
     * cursor já tinha saltado para o ultNSU do lote — as notas restantes nunca
     * mais eram baixadas. O cursor deve parar no último documento processado.
     */
    public function test_teto_de_documentos_nao_pula_o_resto_do_lote(): void
    {
        Storage::fake('local');
        config(['nfegov.sefaz.max_docs' => 2]);

        $unidade = $this->unidade();
        $chaves = [str_repeat('5', 44), str_repeat('6', 44), str_repeat('7', 44)];

        $lote = [];
        foreach ($chaves as $i => $chave) {
            $lote[] = ['nsu' => $i + 1, 'schema' => 'procNFe', 'conteudo' => $this->docZip($this->xmlNfeProc($chave))];
        }

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 0)
            ->once()
            ->andReturn($this->envelope($lote, 3, 3));

        // A execução seguinte retoma DEPOIS do NSU 2 e recebe a 3ª nota.
        $client->shouldReceive('distDFe')
            ->withArgs(fn ($u, $ultNsu) => $ultNsu === 2)
            ->once()
            ->andReturn($this->envelope([$lote[2]], 3, 3));

        app()->instance(SefazClient::class, $client);

        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);
        app(DistDfeService::class)->sync($unidade, $run);

        $this->assertSame(2, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
        $this->assertSame(2, Nfe::withoutGlobalScopes()->count());

        $run2 = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);
        app(DistDfeService::class)->sync($unidade, $run2);

        $this->assertSame(3, Nfe::withoutGlobalScopes()->count());
        $this->assertSame(3, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    /**
     * Regressão: um documento com erro no meio do lote fazia o cursor gravar
     * o ultNSU do lote, pulando o documento com erro e os seguintes.
     */
    public function test_documento_com_erro_mantem_cursor_antes_dele(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn($this->envelope([
            ['nsu' => 1, 'schema' => 'procNFe', 'conteudo' => $this->docZip($this->xmlNfeProc(str_repeat('8', 44)))],
            // XML sem chave de acesso: o importador lança exceção.
            ['nsu' => 2, 'schema' => 'procNFe', 'conteudo' => $this->docZip('<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"/>')],
            ['nsu' => 3, 'schema' => 'procNFe', 'conteudo' => $this->docZip($this->xmlNfeProc(str_repeat('9', 44)))],
        ], 3, 3));

        app()->instance(SefazClient::class, $client);

        try {
            app(DistDfeService::class)->sync($unidade, $run);
            $this->fail('A falha do documento deveria ser propagada.');
        } catch (\RuntimeException) {
            // esperado
        }

        $this->assertSame(1, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
        $this->assertSame(SyncRun::STATUS_ERRO, $run->fresh()->status);
    }
}

<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Domain\Sefaz\SefazClient;
use App\Models\Nfe;
use App\Models\Nsu;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Recuperação pontual de NF-e (por chave ou por NSU).
 *
 * Regra central: NUNCA altera o cursor `Nsu` da unidade — é uma operação
 * lateral, que não pode fazer a sincronização automática rebobinar a fila.
 */
class RecuperacaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        return Unidade::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'tp_amb' => 2,
        ]);
    }

    private function envelope(array $docs, string $cStat, int $ultNsu, int $maxNsu): string
    {
        $d = '';
        foreach ($docs as $doc) {
            $d .= '<docZip NSU="'.$doc['nsu'].'" schema="'.$doc['schema'].'">'.$doc['conteudo'].'</docZip>';
        }

        return '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>'.$cStat.'</cStat><xMotivo>m</xMotivo>'
            .'<ultNSU>'.$ultNsu.'</ultNSU><maxNSU>'.$maxNsu.'</maxNSU>'
            .'<loteDistDFeInt>'.$d.'</loteDistDFeInt></retDistDFeInt>';
    }

    private function xmlNfeProc(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$chave
            .'" versao="4.00"><ide><nNF>1</nNF><serie>1</serie><mod>55</mod><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>11111111111111</CNPJ><xNome>F</xNome></emit><dest><CNPJ>00101980000119</CNPJ></dest>'
            .'<total><ICMSTot><vNF>100.00</vNF></ICMSTot></total></infNFe></NFe>'
            .'<protNFe><infProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function xmlResNFe(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<chNFe>'.$chave.'</chNFe><CNPJ>11111111111111</CNPJ><xNome>F</xNome>'
            .'<vNF>100.00</vNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></resNFe>';
    }

    public function test_recupera_por_nsu_e_importa(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        $chave = str_repeat('1', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->withArgs(fn ($u, $n) => $n === 150)
            ->andReturn($this->envelope([[
                'nsu' => 150, 'schema' => 'procNFe', 'conteudo' => base64_encode(gzencode($this->xmlNfeProc($chave))),
            ]], '138', 150, 200));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porNsu($unidade, 150);

        $this->assertSame(ResultadoRecuperacao::ENCONTRADO, $r->status);
        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());
    }

    public function test_nsu_inexistente_retorna_nao_encontrado(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope([], '137', 100, 100));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porNsu($unidade, 999);

        $this->assertSame(ResultadoRecuperacao::NAO_ENCONTRADO, $r->status);
        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }

    public function test_cstat_656_retorna_bloqueado(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorChave')->once()->andReturn($this->envelope([], '656', 0, 0));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porChave($unidade, str_repeat('2', 44));

        $this->assertSame(ResultadoRecuperacao::BLOQUEADO, $r->status);
    }

    public function test_recuperacao_nao_altera_o_cursor(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        Nsu::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'ultimo_nsu' => 500,
            'max_nsu' => 500,
        ]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope([], '137', 150, 900));
        app()->instance(SefazClient::class, $client);

        app(RecuperacaoService::class)->porNsu($unidade, 150);

        $this->assertSame(500, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    public function test_por_chave_com_137_envia_ciencia_e_reconsulta(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        $chave = str_repeat('3', 44);

        $client = \Mockery::mock(SefazClient::class);
        // 1ª consulta por chave: nada (137). Depois da Ciência, a 2ª consulta
        // devolve o resumo (138), que fecha o ciclo de importação.
        $client->shouldReceive('distDFePorChave')->once()->andReturn($this->envelope([], '137', 0, 0));
        $client->shouldReceive('manifestarCiencia')->once()->with($unidade, $chave)->andReturn(true);
        $client->shouldReceive('distDFePorChave')->once()->andReturn($this->envelope([[
            'nsu' => 100, 'schema' => 'resNFe', 'conteudo' => base64_encode(gzencode($this->xmlResNFe($chave))),
        ]], '138', 100, 100));
        // Resumo → baixa o completo → importa.
        $client->shouldReceive('baixarPorChave')->once()->with($unidade, $chave)
            ->andReturn($this->xmlNfeProc($chave));
        $client->shouldReceive('distDFe')->never();
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porChave($unidade, $chave);

        $this->assertSame(ResultadoRecuperacao::ENCONTRADO, $r->status);
        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());
    }

    public function test_por_chave_fallback_varredura_incremental_encontra_a_nota(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        Nsu::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'ultimo_nsu' => 23921,
            'max_nsu' => 23921,
        ]);
        $chave = str_repeat('4', 44);

        $client = \Mockery::mock(SefazClient::class);
        // Por chave: 137 nas duas tentativas (mesmo após a Ciência).
        $client->shouldReceive('distDFePorChave')->times(2)->andReturn($this->envelope([], '137', 0, 0));
        $client->shouldReceive('manifestarCiencia')->once()->andReturn(true);
        // Fallback: varredura incremental a partir do cursor local encontra o resumo.
        $client->shouldReceive('distDFe')->once()->with($unidade, 23921)
            ->andReturn($this->envelope([[
                'nsu' => 23922, 'schema' => 'resNFe', 'conteudo' => base64_encode(gzencode($this->xmlResNFe($chave))),
            ]], '138', 23922, 23922));
        $client->shouldReceive('baixarPorChave')->once()->andReturn($this->xmlNfeProc($chave));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porChave($unidade, $chave);

        $this->assertSame(ResultadoRecuperacao::ENCONTRADO, $r->status);
        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());

        // E o mais importante: o cursor NÃO pode ter mudado.
        $this->assertSame(23921, Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    public function test_por_chave_sem_documento_retorna_nao_encontrado(): void
    {
        Storage::fake('local');
        $unidade = $this->unidade();
        Nsu::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'ultimo_nsu' => 23921,
            'max_nsu' => 23921,
        ]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorChave')->times(2)->andReturn($this->envelope([], '137', 0, 0));
        $client->shouldReceive('manifestarCiencia')->once()->andReturn(true);
        $client->shouldReceive('distDFe')->once()->andReturn($this->envelope([], '137', 23922, 23922));
        app()->instance(SefazClient::class, $client);

        $r = app(RecuperacaoService::class)->porChave($unidade, str_repeat('5', 44));

        $this->assertSame(ResultadoRecuperacao::NAO_ENCONTRADO, $r->status);
        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }
}

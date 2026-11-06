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
}

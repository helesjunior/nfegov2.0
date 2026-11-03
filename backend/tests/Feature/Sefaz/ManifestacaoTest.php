<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\DownloadService;
use App\Domain\Sefaz\ManifestacaoService;
use App\Domain\Sefaz\SefazClient;
use App\Models\Manifestacao;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manifestação (ciência da operação) e download de XML pela chave.
 */
class ManifestacaoTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function xmlProc(string $chave): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$chave.'">'
            .'<ide><mod>55</mod><serie>1</serie><nNF>1</nNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ</xNome></emit>'
            .'<total><ICMSTot><vNF>10.00</vNF></ICMSTot></total></infNFe></NFe></nfeProc>';
    }

    public function test_ciencia_usa_evento_210210_e_grava_historico(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('1', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('manifestarCiencia')->once()->with($unidade, $chave)->andReturn(true);
        app()->instance(SefazClient::class, $client);

        $ok = app(ManifestacaoService::class)->ciencia($unidade, $chave);

        $this->assertTrue($ok);

        $m = Manifestacao::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($m);
        $this->assertSame('210210', $m->tp_evento);
        $this->assertSame(1, $m->n_seq_evento);
    }

    public function test_ciencia_nao_regrava_historico_em_falha(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('2', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('manifestarCiencia')->once()->andReturn(false);
        app()->instance(SefazClient::class, $client);

        $ok = app(ManifestacaoService::class)->ciencia($unidade, $chave);

        $this->assertFalse($ok);
        $this->assertSame(0, Manifestacao::withoutGlobalScopes()->where('chave', $chave)->count());
    }

    public function test_download_retorna_xml_quando_disponivel(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('3', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('baixarPorChave')->once()->with($unidade, $chave)
            ->andReturn($this->xmlProc($chave));
        app()->instance(SefazClient::class, $client);

        $xml = app(DownloadService::class)->porChave($unidade, $chave);

        $this->assertNotNull($xml);
        $this->assertStringContainsString($chave, $xml);
    }

    public function test_download_retorna_null_quando_indisponivel(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('4', 44);

        // cStat != 138: o cliente devolve null.
        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('baixarPorChave')->once()->andReturn(null);
        app()->instance(SefazClient::class, $client);

        $this->assertNull(app(DownloadService::class)->porChave($unidade, $chave));
    }

    public function test_ciencia_e_idempotente(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('5', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('manifestarCiencia')->once()->andReturn(true);
        app()->instance(SefazClient::class, $client);

        $svc = app(ManifestacaoService::class);
        $svc->ciencia($unidade, $chave);
        // Segunda chamada: já manifestado, não dispara de novo nem duplica.
        $svc->ciencia($unidade, $chave);

        $this->assertSame(1, Manifestacao::withoutGlobalScopes()->where('chave', $chave)->count());
    }
}
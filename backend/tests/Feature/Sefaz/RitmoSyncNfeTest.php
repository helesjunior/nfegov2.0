<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\DistDfeService;
use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Domain\Sefaz\SefazClient;
use App\Domain\Sefaz\SefazGuard;
use App\Jobs\SyncNfeJob;
use App\Models\Certificate;
use App\Models\Nsu;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ritmo da sincronização de NF-e:
 * - regra de 1 hora da SEFAZ depois de ver a fila vazia (senão cStat 656);
 * - continuação logo em seguida quando a execução para no teto com a fila
 *   ainda cheia, em vez de esperar a próxima rodada do agendador.
 */
class RitmoSyncNfeTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true, 'tp_amb' => 2]);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->addDays(30),
        ]);

        return $unidade;
    }

    private function cursor(Unidade $unidade, int $nsu, $filaVaziaEm = null): Nsu
    {
        return Nsu::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'ultimo_nsu' => $nsu,
            'max_nsu' => $nsu,
            'fila_vazia_em' => $filaVaziaEm,
        ]);
    }

    private function envelope(array $docs, int $ultNsu, int $maxNsu, string $cStat = '138', string $xMotivo = 'Ok'): string
    {
        $lote = '';
        foreach ($docs as $doc) {
            $conteudo = base64_encode(gzencode($doc['xml']));
            $lote .= "<docZip NSU=\"{$doc['nsu']}\" schema=\"procNFe\">{$conteudo}</docZip>";
        }

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            ."<tpAmb>2</tpAmb><cStat>{$cStat}</cStat><xMotivo>{$xMotivo}</xMotivo>"
            ."<ultNSU>{$ultNsu}</ultNSU><maxNSU>{$maxNsu}</maxNSU>"
            ."<loteDistDFeInt>{$lote}</loteDistDFeInt></retDistDFeInt>";
    }

    private function xmlNfe(string $chave): string
    {
        return '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe"><NFe><infNFe Id="NFe'.$chave.'">'
            .'<ide><mod>55</mod><serie>1</serie><nNF>1</nNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>99888777000166</CNPJ><xNome>Fornecedor</xNome></emit>'
            .'<det nItem="1"><prod><xProd>Item</xProd><qCom>1</qCom><vProd>1.00</vProd></prod></det>'
            .'<total><ICMSTot><vNF>1.00</vNF></ICMSTot></total>'
            .'</infNFe></NFe></nfeProc>';
    }

    private function rodarJob(Unidade $unidade): void
    {
        (new SyncNfeJob($unidade->id))->handle(app(DistDfeService::class), app(SefazGuard::class));
    }

    public function test_fila_vazia_registra_o_horario_e_nao_pede_continuacao(): void
    {
        $unidade = $this->unidade();
        $this->cursor($unidade, 50);
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn($this->envelope([], 50, 50, '137'));
        app()->instance(SefazClient::class, $client);

        $haMais = app(DistDfeService::class)->sync($unidade, $run);

        $this->assertFalse($haMais);
        $this->assertNotNull(Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('fila_vazia_em'));
        $this->assertGreaterThan(3600, Nsu::segundosParaLiberarFila($unidade->id));
    }

    public function test_teto_de_documentos_com_fila_cheia_pede_continuacao(): void
    {
        Storage::fake('local');
        config(['nfegov.sefaz.max_docs' => 1]);

        $unidade = $this->unidade();
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn($this->envelope([
            ['nsu' => 1, 'xml' => $this->xmlNfe(str_repeat('1', 44))],
            ['nsu' => 2, 'xml' => $this->xmlNfe(str_repeat('2', 44))],
        ], 2, 40));
        app()->instance(SefazClient::class, $client);

        $haMais = app(DistDfeService::class)->sync($unidade, $run);

        $this->assertTrue($haMais);
        $this->assertNull(Nsu::withoutGlobalScopes()->where('unidade_id', $unidade->id)->value('fila_vazia_em'));
        $this->assertSame(0, Nsu::segundosParaLiberarFila($unidade->id));
    }

    public function test_job_nao_consulta_fila_vazia_ha_menos_de_uma_hora_e_se_reagenda(): void
    {
        Queue::fake();

        $unidade = $this->unidade();
        $this->cursor($unidade, 50, now()->subMinutes(10));

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldNotReceive('distDFe');
        app()->instance(SefazClient::class, $client);

        $this->rodarJob($unidade);

        $this->assertSame(0, SyncRun::withoutGlobalScopes()->count());
        Queue::assertPushed(SyncNfeJob::class, function (SyncNfeJob $job) use ($unidade) {
            $atraso = now()->diffInSeconds($job->delay, false);

            // 61 min de espera menos os 10 já passados.
            return $job->unidadeId === $unidade->id && $atraso > 50 * 60 && $atraso <= 51 * 60;
        });
    }

    public function test_job_consulta_quando_a_espera_de_uma_hora_ja_passou(): void
    {
        Queue::fake();

        $unidade = $this->unidade();
        $this->cursor($unidade, 50, now()->subMinutes(62));

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn($this->envelope([], 50, 50, '137'));
        app()->instance(SefazClient::class, $client);

        $this->rodarJob($unidade);

        $this->assertSame(SyncRun::STATUS_SUCESSO, SyncRun::withoutGlobalScopes()->value('status'));
        // Fila vazia de novo: nada de continuação.
        Queue::assertNotPushed(SyncNfeJob::class);
    }

    public function test_job_continua_logo_quando_parou_no_teto(): void
    {
        Storage::fake('local');
        Queue::fake();
        config(['nfegov.sefaz.max_docs' => 1, 'nfegov.sefaz.continuacao_segundos' => 60]);

        $unidade = $this->unidade();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn($this->envelope([
            ['nsu' => 1, 'xml' => $this->xmlNfe(str_repeat('3', 44))],
            ['nsu' => 2, 'xml' => $this->xmlNfe(str_repeat('4', 44))],
        ], 2, 40));
        app()->instance(SefazClient::class, $client);

        $this->rodarJob($unidade);

        Queue::assertPushed(SyncNfeJob::class, function (SyncNfeJob $job) use ($unidade) {
            $atraso = now()->diffInSeconds($job->delay, false);

            return $job->unidadeId === $unidade->id && $atraso > 55 && $atraso <= 60;
        });
    }

    public function test_cstat_656_fecha_a_execucao_com_motivo(): void
    {
        $unidade = $this->unidade();
        $run = SyncRun::factory()->create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn(
            $this->envelope([], 0, 0, '656', 'Rejeicao: Consumo Indevido')
        );
        app()->instance(SefazClient::class, $client);

        $this->assertFalse(app(DistDfeService::class)->sync($unidade, $run));

        $run->refresh();
        $this->assertSame(SyncRun::STATUS_BLOQUEADO, $run->status);
        $this->assertNotNull($run->finalizado_em);
        $this->assertStringContainsString('Consumo Indevido', $run->erro);
    }

    public function test_busca_por_chave_nao_varre_a_fila_durante_a_espera(): void
    {
        $unidade = $this->unidade();
        $this->cursor($unidade, 50, now()->subMinutes(5));
        $chave = str_repeat('5', 44);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorChave')->twice()->andReturn($this->envelope([], 0, 0, '137'));
        $client->shouldReceive('manifestarCiencia')->once()->andReturn(true);
        $client->shouldNotReceive('distDFe');
        app()->instance(SefazClient::class, $client);

        $resultado = app(RecuperacaoService::class)->porChave($unidade, $chave);

        $this->assertSame(ResultadoRecuperacao::BLOQUEADO, $resultado->status);
    }

    public function test_janela_de_escalonamento_cabe_no_intervalo_do_agendador(): void
    {
        config(['nfegov.sync.interval_hours' => 1, 'nfegov.sefaz.slot_window_minutes' => 180]);

        $guard = app(SefazGuard::class);

        foreach (Unidade::factory()->count(20)->create(['tenant_id' => Tenant::factory()->create()->id]) as $unidade) {
            $this->assertLessThan(55 * 60, $guard->slotPara($unidade));
        }
    }
}

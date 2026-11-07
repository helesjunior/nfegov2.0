<?php

namespace Tests\Feature\Nfse;

use App\Domain\Nfse\AdnClient;
use App\Domain\Nfse\AdnGuard;
use App\Jobs\DispatchSyncNfseJob;
use App\Jobs\SyncNfseJob;
use App\Models\Certificate;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Jobs da NFS-e: escolha das unidades, certificado vencido, freio aberto e
 * chave liga/desliga.
 */
class SyncNfseJobTest extends TestCase
{
    use RefreshDatabase;

    private function unidadeComCertificado(bool $vigente = true): Unidade
    {
        $tenant = Tenant::factory()->create(['ativo' => true]);
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'ativo' => true,
            'valido_ate' => $vigente ? now()->addYear() : now()->subDay(),
        ]);

        return $unidade;
    }

    public function test_dispatch_enfileira_so_unidades_com_certificado_vigente(): void
    {
        Queue::fake();
        $ok = $this->unidadeComCertificado();
        $this->unidadeComCertificado(vigente: false);

        (new DispatchSyncNfseJob)->handle(app(AdnGuard::class));

        Queue::assertPushed(SyncNfseJob::class, 1);
        Queue::assertPushed(SyncNfseJob::class, fn ($job) => $job->unidadeId === $ok->id && $job->queue === 'adn');
    }

    public function test_dispatch_nao_faz_nada_com_a_nfse_desligada(): void
    {
        Queue::fake();
        config(['nfegov.adn.habilitado' => false]);
        $this->unidadeComCertificado();

        (new DispatchSyncNfseJob)->handle(app(AdnGuard::class));

        Queue::assertNothingPushed();
    }

    public function test_certificado_vencido_registra_bloqueio_sem_chamar_o_adn(): void
    {
        $unidade = $this->unidadeComCertificado(vigente: false);
        $client = Mockery::mock(AdnClient::class);
        $client->shouldNotReceive('distribuicao');
        $this->app->instance(AdnClient::class, $client);

        (new SyncNfseJob($unidade->id))->handle(app(\App\Domain\Nfse\DistNfseService::class), app(AdnGuard::class));

        $run = SyncRun::withoutGlobalScopes()->first();
        $this->assertSame(SyncRun::STATUS_BLOQUEADO, $run->status);
        $this->assertSame(SyncRun::TIPO_NFSE, $run->tipo);
    }

    public function test_freio_aberto_registra_bloqueio_sem_chamar_o_adn(): void
    {
        $unidade = $this->unidadeComCertificado();
        app(AdnGuard::class)->tripCircuitBreaker($unidade);
        $client = Mockery::mock(AdnClient::class);
        $client->shouldNotReceive('distribuicao');
        $this->app->instance(AdnClient::class, $client);

        (new SyncNfseJob($unidade->id))->handle(app(\App\Domain\Nfse\DistNfseService::class), app(AdnGuard::class));

        $this->assertSame(SyncRun::STATUS_BLOQUEADO, SyncRun::withoutGlobalScopes()->first()->status);
    }
}

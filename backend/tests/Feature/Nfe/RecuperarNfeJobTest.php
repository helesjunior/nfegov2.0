<?php

namespace Tests\Feature\Nfe;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\SefazClient;
use App\Domain\Sefaz\SefazGuard;
use App\Jobs\RecuperarNfeJob;
use App\Jobs\SyncNfeJob;
use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Job de recuperação manual de NF-e (por chave ou por NSU).
 *
 * Regra: só toca a SEFAZ com certificado vigente e circuito fechado —
 * idêntico ao `SyncNfeJob`. Ao final, notifica o solicitante.
 */
class RecuperarNfeJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A recuperação também enfileira um SyncNfeJob da unidade. Com
        // `QUEUE_CONNECTION=sync` ele rodaria inline e bateria na SEFAZ de
        // verdade; o fake isola o teste (o push é verificado no teste próprio).
        Queue::fake();
    }

    private function unidadeComCertificado(array $cert = []): Unidade
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);

        Certificate::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->addYear(),
            'ativo' => true,
        ], $cert));

        return $unidade;
    }

    private function envelope(string $cStat): string
    {
        return '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>'.$cStat.'</cStat><xMotivo>m</xMotivo><ultNSU>0</ultNSU><maxNSU>0</maxNSU>'
            .'</retDistDFeInt>';
    }

    public function test_certificado_vencido_nao_consulta_sefaz_e_notifica(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]); // sem certificado
        $user = User::factory()->comTenant($tenant)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldNotReceive('distDFePorNsu');
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 150))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        $client->shouldNotHaveReceived('distDFePorNsu');
        $this->assertNotNull($user->notifications()->first());
    }

    public function test_recupera_e_notifica(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope('137'));
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        $this->assertNotNull($user->notifications()->first());
    }

    public function test_circuito_aberto_nao_consulta_sefaz(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        app(SefazGuard::class)->tripCircuitBreaker($unidade);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldNotReceive('distDFePorNsu');
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 150))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        $client->shouldNotHaveReceived('distDFePorNsu');
        $this->assertNotNull($user->notifications()->first());
    }

    public function test_super_admin_sem_tenant_e_notificado(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();

        // Super-admin: `tenant_id` nulo (não vinculado à coluna legada). O job
        // roda sob o tenant da UNIDADE, mas a notificação precisa alcançá-lo.
        $superAdmin = User::factory()->create(['tenant_id' => null]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope('137'));
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $superAdmin->id, null, 999))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        $this->assertNotNull(
            $superAdmin->notifications()->first(),
            'O solicitante sem tenant (super-admin) precisa ser notificado.',
        );
    }

    public function test_libera_a_idempotencia_ao_encerrar(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope('137'));
        app()->instance(SefazClient::class, $client);

        $job = new RecuperarNfeJob($unidade->id, $user->id, null, 999);

        // Simula a API tendo reservado a chave.
        Cache::add($job->chaveIdempotencia(), true, 3600);

        $job->handle(app(RecuperacaoService::class), app(SefazGuard::class));

        // Ao terminar, a chave some: o usuário pode tentar novamente.
        $this->assertFalse(Cache::has($job->chaveIdempotencia()));
    }

    public function test_recuperacao_tambem_dispara_sync_da_unidade(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFePorNsu')->once()->andReturn($this->envelope('137'));
        app()->instance(SefazClient::class, $client);

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        // Ao final, a fila da unidade é consumida pelo caminho normal.
        Queue::assertPushed(
            SyncNfeJob::class,
            fn ($job) => $job->unidadeId === $unidade->id,
        );
    }
}

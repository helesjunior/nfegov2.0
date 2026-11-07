<?php

namespace Tests\Feature\Nfe;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\ResultadoRecuperacao;
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

        // Tentativa final (3): o desfecho é notificado, não reagendado.
        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 3))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        $this->assertNotNull($user->notifications()->first());
    }

    public function test_circuito_aberto_na_ultima_tentativa_notifica(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        app(SefazGuard::class)->tripCircuitBreaker($unidade);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldNotReceive('distDFePorNsu');
        app()->instance(SefazClient::class, $client);

        // Sem tentativas restantes, o freio aberto notifica (não reagenda).
        (new RecuperarNfeJob($unidade->id, $user->id, null, 150, 3))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        $client->shouldNotHaveReceived('distDFePorNsu');
        Queue::assertNotPushed(RecuperarNfeJob::class);
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

        (new RecuperarNfeJob($unidade->id, $superAdmin->id, null, 999, 3))->handle(
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

    public function test_nao_encontrado_reagenda_com_backoff(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 1))->handle(
            $service,
            app(SefazGuard::class),
        );

        // Reagenda como tentativa 2, com um delay agendado (1º backoff).
        Queue::assertPushed(
            RecuperarNfeJob::class,
            fn ($job) => $job->tentativa === 2 && $job->delay instanceof \DateTimeInterface,
        );
        // E NÃO dispara o sync (só no desfecho final).
        Queue::assertNotPushed(SyncNfeJob::class);
    }

    public function test_encontrado_nao_reagenda_e_dispara_sync(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        // Testa o caminho de sucesso com um service mockado que devolve
        // ENCONTRADO (evita depender do parse do XML do envelope).
        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, 1));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 1))->handle(
            $service,
            app(SefazGuard::class),
        );

        Queue::assertNotPushed(RecuperarNfeJob::class);
        Queue::assertPushed(SyncNfeJob::class);
    }

    public function test_terceira_tentativa_falha_nao_reagenda_e_notifica(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 3))->handle(
            $service,
            app(SefazGuard::class),
        );

        Queue::assertNotPushed(RecuperarNfeJob::class);
        Queue::assertPushed(SyncNfeJob::class);
        $this->assertNotNull($user->notifications()->first());
    }

    public function test_bloqueado_espera_o_freio_e_nao_consome_tentativa(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        // Simula bloqueio acionado durante a consulta (ex.: cStat 656 dentro do
        // serviço). O freio em si fica aberto para o delay respeitar a espera.
        app(SefazGuard::class)->tripCircuitBreaker($unidade);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldNotReceive('distDFePorNsu');
        app()->instance(SefazClient::class, $client);

        // O serviço não é chamado (freio detectado antes); o job deve reagendar
        // esperando o freio e mantendo a tentativa.
        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 2))->handle(
            app(RecuperacaoService::class),
            app(SefazGuard::class),
        );

        Queue::assertPushed(
            RecuperarNfeJob::class,
            fn ($job) => $job->tentativa === 2 && $job->delay instanceof \DateTimeInterface,
        );
        Queue::assertNotPushed(SyncNfeJob::class);
    }

    public function test_delay_de_bloqueado_espera_o_freio(): void
    {
        $unidade = $this->unidadeComCertificado();
        $guard = app(SefazGuard::class);
        $guard->tripCircuitBreaker($unidade);

        $job = new RecuperarNfeJob($unidade->id, 1, null, 999, 2);
        $delay = $job->delayDoReagendamento(
            ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, '656'),
            $guard,
        );

        // Espera o freio (>= 1 min), nunca negativo nem zero.
        $this->assertGreaterThanOrEqual(60, $delay);
    }

    public function test_delay_nunca_e_menor_que_um_minuto(): void
    {
        $guard = app(SefazGuard::class);
        $job = new RecuperarNfeJob(1, 1, null, 999, 1);

        $delay = $job->delayDoReagendamento(
            ResultadoRecuperacao::de(ResultadoRecuperacao::ERRO, 0, 'x'),
            $guard,
        );

        $this->assertGreaterThanOrEqual(60, $delay);
    }
}

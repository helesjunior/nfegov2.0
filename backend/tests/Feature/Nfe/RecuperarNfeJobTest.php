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
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
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

        // Tentativa final (4): o desfecho é notificado, não reagendado.
        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 4))->handle(
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
        (new RecuperarNfeJob($unidade->id, $user->id, null, 150, 4))->handle(
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

        (new RecuperarNfeJob($unidade->id, $superAdmin->id, null, 999, 4))->handle(
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

        $job = new RecuperarNfeJob($unidade->id, $user->id, null, 999, 4);

        // Simula a API tendo reservado a chave.
        Cache::add($job->chaveIdempotencia(), true, 3600);

        $job->handle(app(RecuperacaoService::class), app(SefazGuard::class));

        // No desfecho final, a chave some: o usuário pode tentar novamente.
        $this->assertFalse(Cache::has($job->chaveIdempotencia()));
    }

    public function test_mantem_a_idempotencia_quando_reagenda(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'));

        $job = new RecuperarNfeJob($unidade->id, $user->id, null, 999, 1);
        Cache::add($job->chaveIdempotencia(), true, 3600);

        $job->handle($service, app(SefazGuard::class));

        // Com retentativa agendada, a chave permanece presa — evita que o
        // usuário dispare uma cadeia paralela durante a espera.
        $this->assertTrue(Cache::has($job->chaveIdempotencia()));
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

    public function test_terceira_tentativa_reagenda_usando_o_intervalo_de_6h(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'));

        // A 3ª tentativa ainda reagenda (para a 4ª); o backoff[2] = 6h é usado.
        $job = new RecuperarNfeJob($unidade->id, $user->id, null, 999, 3);
        $job->handle($service, app(SefazGuard::class));

        Queue::assertPushed(
            RecuperarNfeJob::class,
            fn ($j) => $j->tentativa === 4 && $j->delay instanceof \DateTimeInterface,
        );
        Queue::assertNotPushed(SyncNfeJob::class);

        // O delay da 3ª tentativa é o terceiro intervalo (6h = 21600s).
        $delay = $job->delayDoReagendamento(
            ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'x'),
            app(SefazGuard::class),
        );
        $this->assertSame(21600, $delay);
    }

    public function test_quarta_tentativa_falha_nao_reagenda_e_notifica(): void
    {
        Storage::fake('local');
        $unidade = $this->unidadeComCertificado();
        $user = User::factory()->comTenant($unidade->tenant_id)->create();

        $service = \Mockery::mock(RecuperacaoService::class);
        $service->shouldReceive('porNsu')
            ->once()
            ->andReturn(ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'sem nota'));

        (new RecuperarNfeJob($unidade->id, $user->id, null, 999, 4))->handle(
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

    /**
     * O reagendamento é feito com `self::dispatch` DENTRO do handle. Se o job
     * fosse `ShouldBeUnique` puro, o lock (adquirido no dispatch e liberado só
     * ao fim do handle) suprimiria o próprio retry em produção (Redis/Horizon).
     * `ShouldBeUniqueUntilProcessing` libera o lock no INÍCIO do processamento,
     * permitindo o retry. Este teste trava o contrato.
     */
    public function test_job_permite_reagendar_a_si_mesmo_em_producao(): void
    {
        $job = new RecuperarNfeJob(1, 1, null, 999);

        // `ShouldBeUniqueUntilProcessing` é subinterface de `ShouldBeUnique`,
        // mas o framework checa "until processing" PRIMEIRO: o lock é liberado
        // no início do processamento, viabilizando o `self::dispatch` do retry.
        $this->assertInstanceOf(
            ShouldBeUniqueUntilProcessing::class,
            $job,
            'O job precisa liberar o lock no início do processamento para poder reagendar.',
        );
    }

    /**
     * Payload enfileirado por uma versão anterior (sem o campo `tentativa`)
     * precisa desserializar com o default 1, e não estourar TypeError.
     */
    public function test_payload_antigo_sem_tentativa_desserializa_com_default(): void
    {
        $serializado = serialize(new RecuperarNfeJob(1, 2, 'chave', null));

        // Remove a propriedade `tentativa` do payload, simulando um job
        // enfileirado antes desta versão.
        $legado = preg_replace('/tentativa";i:\d+;/', '', $serializado);

        $job = unserialize($legado);

        $this->assertSame(1, $job->tentativa);
    }
}

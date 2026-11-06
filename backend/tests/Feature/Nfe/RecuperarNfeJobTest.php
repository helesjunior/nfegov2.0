<?php

namespace Tests\Feature\Nfe;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\SefazClient;
use App\Domain\Sefaz\SefazGuard;
use App\Jobs\RecuperarNfeJob;
use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}

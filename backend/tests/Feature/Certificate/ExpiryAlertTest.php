<?php

namespace Tests\Feature\Certificate;

use App\Mail\CertificateExpiringMail;
use App\Models\Certificate;
use App\Models\CertificateAlert;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Alertas proativos de vencimento de certificado (apenas por e-mail).
 *
 * Regras:
 * - Marcos: 60, 30, 15, 7 e 1 dia(s) antes do vencimento.
 * - Um alerta por marco por certificado (idempotente).
 * - E-mail enviado a TODOS os `admin-tenant` do tenant do certificado.
 * - Cobre todos os tenants (roda sem contexto de tenant).
 */
class ExpiryAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
        Mail::fake();
    }

    private function unidadeComAdmins(Tenant $tenant, int $qtdAdmins = 1): Unidade
    {
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        for ($i = 0; $i < $qtdAdmins; $i++) {
            $u = User::factory()->create(['tenant_id' => $tenant->id]);
            $u->assignRole('admin-tenant');
        }

        return $unidade;
    }

    /**
     * Vencimento ancorado no dia-calendário: meio-dia do dia-alvo.
     *
     * Evita flakiness em que `addDays(N)->addHours(h)` cruza a fronteira do
     * dia quando o teste roda à noite (o marco é por dia-calendário).
     */
    private function venceEmDias(int $dias): Carbon
    {
        return now()->addDays($dias)->startOfDay()->addHours(12);
    }

    public function test_alerta_disparado_no_marco_de_30_dias(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidadeComAdmins($tenant);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => $this->venceEmDias(30),
            'ativo' => true,
        ]);

        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertSent(CertificateExpiringMail::class);
        $this->assertDatabaseHas('certificate_alerts', ['dias_restantes' => 30]);
    }

    public function test_nao_dispara_fora_dos_marcos(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidadeComAdmins($tenant);

        // 45 dias não é marco.
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => $this->venceEmDias(45),
            'ativo' => true,
        ]);

        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, CertificateAlert::count());
    }

    public function test_nao_reenvia_o_mesmo_marco(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidadeComAdmins($tenant);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => $this->venceEmDias(7),
            'ativo' => true,
        ]);

        // Roda 2x: só o primeiro envia.
        $this->artisan('certificate-alerts:check')->assertSuccessful();
        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertSame(1, CertificateAlert::where('dias_restantes', 7)->count());
    }

    public function test_envia_para_todos_os_admins_do_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidadeComAdmins($tenant, qtdAdmins: 3);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => $this->venceEmDias(1),
            'ativo' => true,
        ]);

        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertSent(CertificateExpiringMail::class, 3);
    }

    public function test_certificado_inativo_nao_gera_alerta(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidadeComAdmins($tenant);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => $this->venceEmDias(30),
            'ativo' => false,
        ]);

        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_cobre_multiplos_tenants(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = $this->unidadeComAdmins($a);
        $ub = $this->unidadeComAdmins($b);

        Certificate::factory()->create([
            'tenant_id' => $a->id, 'unidade_id' => $ua->id,
            'valido_ate' => $this->venceEmDias(60), 'ativo' => true,
        ]);
        Certificate::factory()->create([
            'tenant_id' => $b->id, 'unidade_id' => $ub->id,
            'valido_ate' => $this->venceEmDias(15), 'ativo' => true,
        ]);

        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertSent(CertificateExpiringMail::class, 2);
    }

    public function test_nao_alerta_certificado_ja_vencido(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidadeComAdmins($tenant);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subDay(),
            'ativo' => true,
        ]);

        $this->artisan('certificate-alerts:check')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
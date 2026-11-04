<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Painel: contagem de certificados vencidos explicitamente marcados.
 *
 * `certificados.expirados` conta os certificados com `expirado_em` preenchido
 * (vindos do comando `certificate-expiry:mark`), de forma ADITIVA — a chave
 * `vencidos` (por data) continua existindo para não quebrar contrato.
 */
class DashboardCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('admin-tenant');
        // O papel admin-tenant exige MFA confirmado (middleware `mfa`).
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    public function test_dashboard_conta_certificados_marcados_como_expirados(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->admin($tenant);
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        // Um vencido marcado explicitamente.
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subDay(),
            'ativo' => true,
            'expirado_em' => now()->subDay(),
        ]);

        // Um vigente: não conta.
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->addDays(30),
            'ativo' => true,
            'expirado_em' => null,
        ]);

        $resp = $this->actingAs($u)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(1, $resp->json('certificados.expirados'));
    }

    public function test_dashboard_nao_conta_certificado_marcado_que_foi_rotacionado(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->admin($tenant);
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        // Certificado que venceu, foi marcado e depois ROTACIONADO: a rotação
        // o desativa (ativo=false) mas preserva expirado_em. Ele não é mais o
        // certificado corrente, então não deve contar como "expirado" no painel
        // (a tela da unidade também não o mostra: activeFor() filtra ativo=true).
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subDay(),
            'ativo' => false,
            'expirado_em' => now()->subDay(),
        ]);

        // Certificado corrente e vigente.
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->addDays(30),
            'ativo' => true,
            'expirado_em' => null,
        ]);

        $resp = $this->actingAs($u)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0, $resp->json('certificados.expirados'));
    }

    public function test_dashboard_nao_conta_certificados_de_outro_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $u = $this->admin($a);

        $unidadeB = Unidade::factory()->create(['tenant_id' => $b->id]);
        Certificate::factory()->create([
            'tenant_id' => $b->id,
            'unidade_id' => $unidadeB->id,
            'valido_ate' => now()->subDay(),
            'ativo' => true,
            'expirado_em' => now()->subDay(),
        ]);

        $resp = $this->actingAs($u)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0, $resp->json('certificados.expirados'));
    }
}

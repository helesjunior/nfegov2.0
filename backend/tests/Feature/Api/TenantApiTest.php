<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['tenant_id' => null]);
        $u->assignRole('super-admin');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    public function test_super_admin_lista_entidades(): void
    {
        Tenant::factory()->count(3)->create();

        $this->actingAs($this->superAdmin())->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_super_admin_cria_entidade(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/tenants', ['nome' => 'Prefeitura X', 'cnpj' => '11222333000181'])
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Prefeitura X');

        $this->assertDatabaseHas('tenants', ['nome' => 'Prefeitura X']);
    }

    public function test_super_admin_edita_entidade(): void
    {
        $t = Tenant::factory()->create(['nome' => 'Antiga']);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/tenants/{$t->id}", ['nome' => 'Nova'])
            ->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => $t->id, 'nome' => 'Nova']);
    }

    public function test_super_admin_desativa_entidade(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/tenants/{$t->id}/ativo", ['ativo' => false])
            ->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => $t->id, 'ativo' => false]);
    }

    public function test_admin_tenant_nao_gerencia_entidades(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->comTenant($t)->create();
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($u)->getJson('/api/tenants')->assertForbidden();
        $this->actingAs($u)->postJson('/api/tenants', ['nome' => 'Z'])->assertForbidden();
    }

    public function test_detalhe_de_entidade(): void
    {
        $t = Tenant::factory()->create();

        $this->actingAs($this->superAdmin())->getJson("/api/tenants/{$t->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $t->id);
    }

    public function test_mutacao_de_entidade_exige_mfa_recente(): void
    {
        // Super-admin com MFA confirmado, mas SEM verificação recente (step-up):
        // a criação/edição/desativação de entidades deve ser barrada (403).
        $u = User::factory()->create(['tenant_id' => null]);
        $u->assignRole('super-admin');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => null,
        ])->save();

        $t = Tenant::factory()->create();

        $this->actingAs($u)
            ->postJson('/api/tenants', ['nome' => 'Sem step-up'])
            ->assertForbidden();

        $this->actingAs($u)
            ->putJson("/api/tenants/{$t->id}", ['nome' => 'Sem step-up'])
            ->assertForbidden();

        // A leitura continua permitida (índice/detalhe não exigem step-up).
        $this->actingAs($u)->getJson('/api/tenants')->assertOk();
    }
}
<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessaoTenantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function mfa(User $u): User
    {
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $u;
    }

    public function test_me_expoe_entidades_disponiveis_e_pode_todas_para_super_admin(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.pode_todas', true)
            ->assertJsonPath('user.tenant_ativo', null)
            ->assertJsonCount(2, 'user.tenants_disponiveis');
    }

    public function test_me_mostra_apenas_vinculadas_para_admin_tenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        Tenant::factory()->create(['ativo' => true]);
        $u = $this->mfa(User::factory()->comTenant($a)->create());
        $u->assignRole('admin-tenant');

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.pode_todas', false)
            ->assertJsonPath('user.tenant_ativo.id', $a->id)
            ->assertJsonCount(1, 'user.tenants_disponiveis');
    }

    public function test_super_admin_troca_para_qualquer_tenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->postJson('/api/sessao/tenant', ['tenant_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('tenant_ativo.id', $a->id);

        $this->actingAs($super)->getJson('/api/me')->assertJsonPath('user.tenant_ativo.id', $a->id);
    }

    public function test_super_admin_pode_voltar_para_todas(): void
    {
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->postJson('/api/sessao/tenant', ['tenant_id' => null])
            ->assertOk()
            ->assertJsonPath('tenant_ativo', null);
    }

    public function test_usuario_nao_troca_para_tenant_sem_vinculo(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $outro = Tenant::factory()->create(['ativo' => true]);
        $u = $this->mfa(User::factory()->comTenant($a)->create());
        $u->assignRole('admin-tenant');

        $this->actingAs($u)->postJson('/api/sessao/tenant', ['tenant_id' => $outro->id])
            ->assertForbidden();
    }

    public function test_nao_seleciona_tenant_inativo(): void
    {
        $inativo = Tenant::factory()->create(['ativo' => false]);
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->postJson('/api/sessao/tenant', ['tenant_id' => $inativo->id])
            ->assertStatus(422);
    }

    public function test_login_recusa_usuario_inativo(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($t)->create([
            'password' => \Illuminate\Support\Facades\Hash::make('senha-forte'),
            'ativo' => false,
        ]);
        $u->assignRole('operador');

        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'])
            ->assertStatus(422);
        $this->assertGuest();
    }

    public function test_sessao_de_usuario_inativado_e_derrubada(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);
        $u = $this->mfa(User::factory()->comTenant($t)->create());
        $u->assignRole('admin-tenant');

        $this->actingAs($u)->getJson('/api/me')->assertOk();

        $u->forceFill(['ativo' => false])->save();
        $this->actingAs($u->fresh())->getJson('/api/me')->assertForbidden();
    }
}
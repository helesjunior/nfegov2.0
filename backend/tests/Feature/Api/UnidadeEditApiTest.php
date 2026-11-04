<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnidadeEditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function admin(Tenant $t): User
    {
        $u = User::factory()->comTenant($t)->create();
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    public function test_detalhe_da_unidade(): void
    {
        $t = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $t->id]);

        $this->actingAs($this->admin($t))->getJson("/api/unidades/{$u->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $u->id)
            ->assertJsonPath('data.tenant_id', $t->id);
    }

    public function test_edita_unidade(): void
    {
        $t = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $t->id, 'nome' => 'Antiga']);

        $this->actingAs($this->admin($t))->putJson("/api/unidades/{$u->id}", [
            'nome' => 'Corrigida',
            'cnpj' => $u->cnpj,
            'uf' => $u->uf,
        ])->assertOk();

        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'nome' => 'Corrigida']);
    }

    public function test_editar_ignora_tentativa_de_mover_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $a->id]);

        $this->actingAs($this->admin($a))->putJson("/api/unidades/{$u->id}", [
            'nome' => 'X',
            'cnpj' => $u->cnpj,
            'uf' => $u->uf,
            'tenant_id' => $b->id,
        ])->assertOk();

        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'tenant_id' => $a->id]);
    }

    public function test_desativa_e_reativa_unidade(): void
    {
        $t = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $t->id, 'ativo' => true]);
        $admin = $this->admin($t);

        $this->actingAs($admin)->putJson("/api/unidades/{$u->id}/ativo", ['ativo' => false])->assertOk();
        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'ativo' => false]);

        $this->actingAs($admin)->putJson("/api/unidades/{$u->id}/ativo", ['ativo' => true])->assertOk();
        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'ativo' => true]);
    }

    public function test_editar_unidade_de_outro_tenant_retorna_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $b->id]);

        $this->actingAs($this->admin($a))->putJson("/api/unidades/{$u->id}", [
            'nome' => 'X', 'cnpj' => $u->cnpj, 'uf' => $u->uf,
        ])->assertNotFound();
    }

    public function test_super_admin_em_todas_exige_tenant_ao_criar_unidade(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');
        $super->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        // Sem tenant_id no corpo e sem contexto: deve falhar na validação.
        $this->actingAs($super)->postJson('/api/unidades', [
            'nome' => 'Sem entidade', 'cnpj' => '11222333000181', 'uf' => 'SP',
        ])->assertStatus(422);

        // Com tenant_id válido: cria.
        $t = Tenant::factory()->create();
        $this->actingAs($super)->postJson('/api/unidades', [
            'nome' => 'Com entidade', 'cnpj' => '11222333000181', 'uf' => 'SP', 'tenant_id' => $t->id,
        ])->assertCreated();

        $this->assertDatabaseHas('unidades', ['tenant_id' => $t->id, 'cnpj' => '11222333000181']);
    }
}
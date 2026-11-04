<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserApiTest extends TestCase
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
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    private function superAdmin(): User
    {
        $u = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $u->assignRole('super-admin');

        return $u;
    }

    private function adminTenant(Tenant $t): User
    {
        $u = $this->mfa(User::factory()->comTenant($t)->create());
        $u->assignRole('admin-tenant');

        return $u;
    }

    public function test_admin_tenant_lista_apenas_usuarios_vinculados_ao_tenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);

        $vinculadoViaMask = User::factory()->create(['tenant_id' => $b->id]); // coluna legada aponta p/ B
        $vinculadoViaMask->tenants()->attach($a->id);                          // mas vínculo real é A
        $vinculadoViaMask->assignRole('consulta');

        $deB = User::factory()->comTenant($b)->create();
        $deB->assignRole('consulta');

        $resp = $this->actingAs($this->adminTenant($a))->getJson('/api/usuarios');

        $resp->assertOk();
        $emails = collect($resp->json('data'))->pluck('email')->all();
        $this->assertContains($vinculadoViaMask->email, $emails); // Review Focus #1
        $this->assertNotContains($deB->email, $emails);
    }

    public function test_super_admin_em_todas_lista_todos(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        User::factory()->comTenant($a)->create();
        User::factory()->create(['tenant_id' => null])->assignRole('consulta');

        $resp = $this->actingAs($this->superAdmin())->getJson('/api/usuarios');
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(3, $resp->json('meta.total'));
    }

    public function test_admin_tenant_cria_usuario_vinculado_ao_proprio_tenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);

        $resp = $this->actingAs($this->adminTenant($a))->postJson('/api/usuarios', [
            'name' => 'Novo Operador',
            'email' => 'novo@x.test',
            'password' => 'senha-forte-1',
            'papel' => 'operador',
            'tenants' => [$a->id],
        ]);

        $resp->assertCreated();
        $u = User::whereEmail('novo@x.test')->first();
        $this->assertTrue($u->hasRole('operador'));
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $a->id, 'user_id' => $u->id]);
    }

    public function test_admin_tenant_nao_vincula_a_tenant_alheio(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $outro = Tenant::factory()->create(['ativo' => true]);

        $this->actingAs($this->adminTenant($a))->postJson('/api/usuarios', [
            'name' => 'Intruso', 'email' => 'intruso@x.test', 'password' => 'senha-forte-1',
            'papel' => 'operador', 'tenants' => [$outro->id],
        ])->assertForbidden();
    }

    public function test_admin_tenant_nao_cria_super_admin(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);

        $this->actingAs($this->adminTenant($a))->postJson('/api/usuarios', [
            'name' => 'Fake Super', 'email' => 'fake@x.test', 'password' => 'senha-forte-1',
            'papel' => 'super-admin', 'tenants' => [$a->id],
        ])->assertForbidden();
    }

    public function test_super_admin_cria_multitenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);

        $this->actingAs($this->superAdmin())->postJson('/api/usuarios', [
            'name' => 'Procurador', 'email' => 'proc@x.test', 'password' => 'senha-forte-1',
            'papel' => 'admin-tenant', 'tenants' => [$a->id, $b->id],
        ])->assertCreated();

        $u = User::whereEmail('proc@x.test')->first();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $u->tenants()->pluck('tenants.id')->all());
    }

    public function test_editar_usuario_troca_papel_e_vinculos(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $alvo = User::factory()->comTenant($a)->create();
        $alvo->assignRole('operador');

        $this->actingAs($this->superAdmin())->putJson("/api/usuarios/{$alvo->id}", [
            'name' => 'Renomeado', 'email' => $alvo->email, 'papel' => 'consulta',
            'tenants' => [$b->id],
        ])->assertOk();

        $alvo->refresh();
        $this->assertTrue($alvo->hasRole('consulta'));
        $this->assertSame([$b->id], $alvo->tenants()->pluck('tenants.id')->map(fn ($i) => (int) $i)->all());
    }

    public function test_admin_tenant_nao_edita_a_propria_conta(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $eu = $this->adminTenant($a);

        $this->actingAs($eu)->putJson("/api/usuarios/{$eu->id}", [
            'name' => 'Eu', 'email' => $eu->email, 'papel' => 'consulta', 'tenants' => [$a->id],
        ])->assertForbidden();
    }

    public function test_desativar_usuario(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $alvo = User::factory()->comTenant($a)->create();
        $alvo->assignRole('operador');

        $this->actingAs($this->adminTenant($a))->putJson("/api/usuarios/{$alvo->id}/ativo", ['ativo' => false])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $alvo->id, 'ativo' => false]);
    }

    public function test_ninguem_desativa_a_propria_conta(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $eu = $this->adminTenant($a);

        $this->actingAs($eu)->putJson("/api/usuarios/{$eu->id}/ativo", ['ativo' => false])
            ->assertForbidden();
    }

    public function test_email_duplicado_retorna_422(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        User::factory()->create(['email' => 'existe@x.test']);

        $this->actingAs($this->superAdmin())->postJson('/api/usuarios', [
            'name' => 'Dup', 'email' => 'existe@x.test', 'password' => 'senha-forte-1',
            'papel' => 'operador', 'tenants' => [$a->id],
        ])->assertStatus(422);
    }
}
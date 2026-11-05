<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function operador(string $email = 'op@orgao.gov.br', string $senha = 'senha12345'): User
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create([
            'email' => $email,
            'password' => Hash::make($senha),
        ]);
        $u->assignRole(['operador']);

        return $u;
    }

    public function test_atualiza_nome_sem_exigir_senha(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->putJson('/api/me', ['name' => 'Novo Nome', 'email' => 'op@orgao.gov.br'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Novo Nome');

        $this->assertDatabaseHas('users', ['id' => $u->id, 'name' => 'Novo Nome']);
    }

    public function test_troca_email_exige_senha_atual(): void
    {
        $u = $this->operador();

        // Sem a senha atual: falha de validação.
        $this->actingAs($u)->putJson('/api/me', ['name' => $u->name, 'email' => 'novo@orgao.gov.br'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        // Com a senha atual: troca.
        $this->actingAs($u)->putJson('/api/me', [
            'name' => $u->name,
            'email' => 'novo@orgao.gov.br',
            'current_password' => 'senha12345',
        ])->assertOk()->assertJsonPath('user.email', 'novo@orgao.gov.br');
    }

    public function test_email_duplicado_falha(): void
    {
        $this->operador('ocupado@orgao.gov.br');
        $u = $this->operador('eu@orgao.gov.br');

        $this->actingAs($u)->putJson('/api/me', [
            'name' => $u->name,
            'email' => 'ocupado@orgao.gov.br',
            'current_password' => 'senha12345',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_admin_sem_mfa_confirmado_acessa_o_perfil(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create(['password' => Hash::make('senha12345')]);
        $u->assignRole(['admin-tenant']); // exige MFA, mas ainda não confirmou.

        $this->actingAs($u)->putJson('/api/me', ['name' => 'Ainda Acesso', 'email' => $u->email])
            ->assertOk();
    }

    public function test_troca_senha_exige_senha_atual_correta(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->putJson('/api/me/password', [
            'current_password' => 'errada',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->actingAs($u)->putJson('/api/me/password', [
            'current_password' => 'senha12345',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertOk();

        $this->assertTrue(Hash::check('novaSenha123', $u->fresh()->password));
    }

    public function test_nova_senha_precisa_ser_confirmada(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->putJson('/api/me/password', [
            'current_password' => 'senha12345',
            'password' => 'novaSenha123',
            'password_confirmation' => 'divergente',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_continua_autenticado_apos_trocar_a_senha(): void
    {
        $u = $this->operador();

        $this->actingAs($u)->putJson('/api/me/password', [
            'current_password' => 'senha12345',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertOk();

        $this->actingAs($u)->getJson('/api/me')->assertOk();
    }
}
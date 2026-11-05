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

    public function test_mudar_apenas_a_caixa_do_email_exige_senha_atual(): void
    {
        $u = $this->operador('op@orgao.gov.br');

        // Sem a senha atual: falha, pois a caixa também afeta o login.
        $this->actingAs($u)->putJson('/api/me', ['name' => $u->name, 'email' => 'OP@orgao.gov.br'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
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

    private function comMfa(User $u): string
    {
        $secret = app(\App\Services\TwoFactorService::class)->gerarSegredo();
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        return $secret;
    }

    public function test_operador_desabilita_mfa_com_codigo_valido(): void
    {
        $u = $this->operador();
        $secret = $this->comMfa($u);
        $codigo = app(\App\Services\TwoFactorService::class)->codigoAtual($secret);

        $this->actingAs($u)->deleteJson('/api/me/mfa', ['code' => $codigo])
            ->assertOk()
            ->assertJsonPath('user.mfa_confirmado', false);

        $this->assertNull($u->fresh()->two_factor_confirmed_at);
        $this->assertNull($u->fresh()->two_factor_secret);
    }

    public function test_codigo_invalido_nao_desabilita(): void
    {
        $u = $this->operador();
        $this->comMfa($u);

        $this->actingAs($u)->deleteJson('/api/me/mfa', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertNotNull($u->fresh()->two_factor_confirmed_at);
    }

    public function test_papel_obrigatorio_nao_pode_desabilitar(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create(['password' => Hash::make('senha12345')]);
        $u->assignRole(['admin-tenant']);
        $secret = $this->comMfa($u);
        $codigo = app(\App\Services\TwoFactorService::class)->codigoAtual($secret);

        $this->actingAs($u)->deleteJson('/api/me/mfa', ['code' => $codigo])
            ->assertForbidden();

        $this->assertNotNull($u->fresh()->two_factor_confirmed_at);
    }

    public function test_mutacoes_de_perfil_sao_auditadas(): void
    {
        $u = $this->operador();
        $secret = $this->comMfa($u);
        $codigo = app(\App\Services\TwoFactorService::class)->codigoAtual($secret);

        $this->actingAs($u)->putJson('/api/me', [
            'name' => 'Novo Nome',
            'email' => 'novo@orgao.gov.br',
            'current_password' => 'senha12345',
        ])->assertOk();

        $this->actingAs($u)->putJson('/api/me/password', [
            'current_password' => 'senha12345',
            'password' => 'novaSenha123',
            'password_confirmation' => 'novaSenha123',
        ])->assertOk();

        $this->actingAs($u)->deleteJson('/api/me/mfa', ['code' => $codigo])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['acao' => 'usuario.perfil.atualizado', 'user_id' => $u->id]);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'usuario.senha.alterada', 'user_id' => $u->id]);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'usuario.mfa.desabilitado', 'user_id' => $u->id]);
    }
}
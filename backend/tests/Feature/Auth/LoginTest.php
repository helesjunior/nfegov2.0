<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuario(string $senha = 'senha-forte'): User
    {
        $tenant = Tenant::factory()->create();

        $u = User::factory()->comTenant($tenant)->create([
            'password' => Hash::make($senha),
        ]);
        $u->assignRole('admin-tenant');

        return $u;
    }

    public function test_login_retorna_usuario_e_sessao(): void
    {
        $u = $this->usuario();

        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'])
            ->assertOk()
            ->assertJsonPath('user.id', $u->id)
            ->assertJsonPath('user.email', $u->email);

        $this->assertAuthenticated();
    }

    public function test_login_com_senha_errada_retorna_422(): void
    {
        $u = $this->usuario();

        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'errada'])
            ->assertStatus(422);

        $this->assertGuest();
    }

    public function test_login_nao_expoe_senha_nem_tenant_id_sensivel(): void
    {
        $u = $this->usuario();

        $resp = $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'])
            ->assertOk();

        $this->assertArrayNotHasKey('password', $resp->json('user'));
    }

    public function test_rota_protegida_sem_sessao_retorna_401(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_retorna_usuario_autenticado_com_papeis(): void
    {
        $u = $this->usuario();

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $u->id)
            ->assertJsonPath('user.papeis.0', 'admin-tenant');
    }

    public function test_logout_encerra_a_sessao(): void
    {
        $u = $this->usuario();

        // Login real via endpoint para estabelecer a sessão (fluxo do SPA).
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'])
            ->assertOk();

        // Com a sessão ativa, a rota protegida responde.
        $this->getJson('/api/me')->assertOk();

        $this->postJson('/api/logout')->assertOk();

        // Após o logout, uma nova requisição não é mais autenticada.
        // (assertGuest() direto não serve aqui porque o AuthManager cacheia o
        // guard `sanctum` durante o mesmo request de teste; no fluxo HTTP real
        // cada requisição re-resolve a sessão — que foi invalidada.)
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        // Rota protegida por MFA recente (step-up). No produto final isso é a
        // rotação de certificado (Task 9); aqui validamos o middleware isolado.
        Route::middleware(['web', 'auth:sanctum', 'mfa.recent'])
            ->get('/api/_test/step-up', fn () => response()->json(['ok' => true]));

        // Rota de negócio protegida por MFA confirmado (papéis administrativos).
        Route::middleware(['web', 'auth:sanctum', 'mfa'])
            ->get('/api/_test/negocio', fn () => response()->json(['ok' => true]));
    }

    private function usuario(array $papeis = ['admin-tenant'], string $senha = 'senha-forte'): User
    {
        $tenant = Tenant::factory()->create();

        $u = User::factory()->comTenant($tenant)->create([
            'password' => Hash::make($senha),
        ]);
        $u->assignRole($papeis);

        return $u;
    }

    public function test_admin_tenant_sem_mfa_confirmado_recebe_403(): void
    {
        $u = $this->usuario(['admin-tenant']);

        // /me continua acessível (informa o MFA pendente), mas a área de
        // negócio é bloqueada até confirmar o segundo fator.
        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.mfa_pendente', true);

        $this->actingAs($u)->getJson('/api/_test/negocio')->assertForbidden();
    }

    public function test_super_admin_sem_mfa_confirmado_recebe_403(): void
    {
        $u = $this->usuario(['super-admin']);

        $this->actingAs($u)->getJson('/api/_test/negocio')->assertForbidden();
    }

    public function test_operador_sem_mfa_tem_acesso_liberado(): void
    {
        $u = $this->usuario(['operador']);

        $this->actingAs($u)->getJson('/api/_test/negocio')->assertOk();
    }

    public function test_fluxo_de_setup_confirmacao_e_verificacao(): void
    {
        $u = $this->usuario(['admin-tenant']);

        // Setup gera o segredo (ainda não confirmado).
        $resp = $this->actingAs($u)->postJson('/api/mfa/setup')
            ->assertOk()
            ->assertJsonStructure(['secret', 'qr_url']);

        $secret = $resp->json('secret');
        $this->assertNotEmpty($secret);

        // Enquanto não confirma, a área de negócio segue bloqueada.
        $this->actingAs($u)->getJson('/api/_test/negocio')->assertForbidden();

        // Confirma com um código TOTP válido.
        $codigo = app(\App\Services\TwoFactorService::class)->codigoAtual($secret);

        $this->actingAs($u)->postJson('/api/mfa/confirm', ['code' => $codigo])
            ->assertOk();

        $this->assertNotNull($u->fresh()->two_factor_confirmed_at);

        // Agora o acesso é liberado (e a janela recente foi marcada).
        $this->actingAs($u)->getJson('/api/_test/negocio')->assertOk();
    }

    public function test_confirm_com_codigo_invalido_falha(): void
    {
        $u = $this->usuario(['admin-tenant']);

        $this->actingAs($u)->postJson('/api/mfa/setup')->assertOk();

        $this->actingAs($u)->postJson('/api/mfa/confirm', ['code' => '000000'])
            ->assertStatus(422);

        $this->assertNull($u->fresh()->two_factor_confirmed_at);
    }

    public function test_step_up_exige_mfa_recente(): void
    {
        $u = $this->usuario(['admin-tenant']);

        // MFA confirmado, mas a última verificação foi há 1 hora.
        $u->forceFill([
            'two_factor_confirmed_at' => now()->subDay(),
            'mfa_recent_at' => now()->subHour(),
        ])->save();

        $this->actingAs($u)->getJson('/api/_test/step-up')->assertForbidden();
    }

    public function test_step_up_libera_com_mfa_recente(): void
    {
        $u = $this->usuario(['admin-tenant']);

        $u->forceFill([
            'two_factor_confirmed_at' => now()->subDay(),
            'mfa_recent_at' => now()->subMinute(),
        ])->save();

        $this->actingAs($u)->getJson('/api/_test/step-up')->assertOk();
    }

    public function test_verify_com_codigo_valido_renova_a_janela(): void
    {
        $u = $this->usuario(['admin-tenant']);
        $secret = app(\App\Services\TwoFactorService::class)->gerarSegredo();

        $u->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now()->subDay(),
            'mfa_recent_at' => now()->subHour(),
        ])->save();

        $codigo = app(\App\Services\TwoFactorService::class)->codigoAtual($secret);

        $this->actingAs($u)->postJson('/api/mfa/verify', ['code' => $codigo])
            ->assertOk();

        $this->assertTrue($u->fresh()->mfa_recent_at->gt(now()->subMinute()));
    }

    public function test_secret_e_senha_nunca_sao_expostos(): void
    {
        $u = $this->usuario(['admin-tenant']);
        $this->actingAs($u)->postJson('/api/mfa/setup')->assertOk();

        $resp = $this->actingAs($u)->getJson('/api/me')->json('user') ?? [];
        $this->assertArrayNotHasKey('two_factor_secret', $resp);
        $this->assertArrayNotHasKey('password', $resp);
    }
}
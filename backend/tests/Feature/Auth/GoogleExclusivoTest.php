<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\GoogleLogin;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modo exclusivo do login com Google (NFEGOV_GOOGLE_EXCLUSIVO).
 *
 * Só vale com o Google no ar E o autocadastro 'pendente'. Aí: quem não é
 * super-admin não cadastra usuário nem entra por senha com e-mail do domínio;
 * o admin-tenant aprova os pendentes para a SUA entidade; o super-admin segue
 * como sempre (senha + MFA, cadastro).
 */
class GoogleExclusivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function ligar(bool $exclusivo = true, string $autocadastro = 'pendente'): void
    {
        config([
            'nfegov.google_login.habilitado' => true,
            'nfegov.google_login.dominios' => ['exemplo.com.br'],
            'nfegov.google_login.autocadastro' => $autocadastro,
            'nfegov.google_login.exclusivo' => $exclusivo,
            'services.google.client_id' => 'client-id-teste',
            'services.google.client_secret' => 'client-secret-teste',
        ]);
    }

    private function usuario(?Tenant $tenant, string $papel, array $atributos = []): User
    {
        $factory = User::factory();
        $u = ($tenant ? $factory->comTenant($tenant) : $factory)->create($atributos);
        $u->assignRole($papel);
        $u->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    /** Cadastro criado pelo Google e ainda sem entidade. */
    private function pendente(string $email = 'nova@exemplo.com.br'): User
    {
        $u = User::factory()->create([
            'email' => $email, 'ativo' => false, 'tenant_id' => null,
        ]);
        $u->forceFill(['google_id' => 'sub-' . $email, 'aprovacao_pendente' => true])->save();
        $u->assignRole('consulta');

        return $u;
    }

    private function entrar(string $email, string $senha = 'password')
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $senha]);
    }

    // ---- quando vale ----------------------------------------------------------

    public function test_so_vale_com_o_google_no_ar_e_o_autocadastro_pendente(): void
    {
        config(['nfegov.google_login.exclusivo' => true]);
        $this->assertFalse(GoogleLogin::exclusivo(), 'sem Google no ar');

        $this->ligar(true, 'desligado');
        $this->assertFalse(GoogleLogin::exclusivo(), 'sem autocadastro pendente ninguém seria cadastrado');

        $this->ligar(true, 'pendente');
        $this->assertTrue(GoogleLogin::exclusivo());

        $this->ligar(false);
        $this->assertFalse(GoogleLogin::exclusivo(), 'chave desligada');
    }

    public function test_opcoes_publicas_dizem_se_o_modo_exclusivo_esta_valendo(): void
    {
        $this->ligar(false);
        $this->getJson('/api/login/opcoes')->assertJsonPath('data.google_exclusivo', false);

        $this->ligar(true);
        $this->getJson('/api/login/opcoes')->assertJsonPath('data.google_exclusivo', true);
    }

    // ---- login por senha ------------------------------------------------------

    public function test_email_do_dominio_nao_entra_por_senha_e_a_tentativa_e_auditada(): void
    {
        $this->ligar();
        $this->usuario(Tenant::factory()->create(), 'admin-tenant', ['email' => 'maria@exemplo.com.br']);

        $this->entrar('maria@exemplo.com.br')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['acao' => 'login.senha.bloqueado']);
    }

    public function test_senha_errada_continua_com_a_mensagem_generica(): void
    {
        $this->ligar();
        $this->usuario(Tenant::factory()->create(), 'consulta', ['email' => 'maria@exemplo.com.br']);

        $r = $this->entrar('maria@exemplo.com.br', 'errada')->assertStatus(422);

        $this->assertStringContainsString('Credenciais inválidas', json_encode($r->json(), JSON_UNESCAPED_UNICODE));
    }

    public function test_super_admin_com_email_do_dominio_continua_entrando_por_senha(): void
    {
        $this->ligar();
        $this->usuario(null, 'super-admin', ['email' => 'root@exemplo.com.br', 'tenant_id' => null]);

        $this->entrar('root@exemplo.com.br')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_email_fora_do_dominio_continua_entrando_por_senha(): void
    {
        $this->ligar();
        $this->usuario(Tenant::factory()->create(), 'consulta', ['email' => 'fora@gmail.com']);

        $this->entrar('fora@gmail.com')->assertOk();
    }

    public function test_sem_o_modo_exclusivo_o_dominio_entra_por_senha_como_antes(): void
    {
        $this->ligar(false);
        $this->usuario(Tenant::factory()->create(), 'consulta', ['email' => 'maria@exemplo.com.br']);

        $this->entrar('maria@exemplo.com.br')->assertOk();
    }

    // ---- cadastro manual ------------------------------------------------------

    private function novoUsuario(Tenant $t): array
    {
        return [
            'name' => 'Novo', 'email' => 'novo@x.test', 'password' => 'senha-forte-1',
            'papel' => 'operador', 'tenants' => [$t->id],
        ];
    }

    public function test_admin_tenant_nao_cadastra_usuario_no_modo_exclusivo(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();

        $this->actingAs($this->usuario($t, 'admin-tenant'))
            ->postJson('/api/usuarios', $this->novoUsuario($t))
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'novo@x.test']);
    }

    public function test_super_admin_continua_cadastrando_no_modo_exclusivo(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();

        $this->actingAs($this->usuario(null, 'super-admin', ['tenant_id' => null]))
            ->postJson('/api/usuarios', $this->novoUsuario($t))
            ->assertCreated();
    }

    public function test_admin_tenant_cadastra_normalmente_sem_o_modo_exclusivo(): void
    {
        $this->ligar(false);
        $t = Tenant::factory()->create();

        $this->actingAs($this->usuario($t, 'admin-tenant'))
            ->postJson('/api/usuarios', $this->novoUsuario($t))
            ->assertCreated();
    }

    // ---- e-mail travado -------------------------------------------------------

    private function edicao(User $alvo, array $mudancas = []): array
    {
        return array_merge([
            'name' => $alvo->name,
            'email' => $alvo->email,
            'papel' => 'consulta',
            'tenants' => $alvo->tenants()->pluck('tenants.id')->all(),
        ], $mudancas);
    }

    public function test_admin_tenant_nao_troca_o_email_de_um_usuario_mas_edita_o_resto(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $alvo = $this->usuario($t, 'consulta', ['email' => 'ana@exemplo.com.br']);
        $admin = $this->usuario($t, 'admin-tenant');

        $this->actingAs($admin)->putJson("/api/usuarios/{$alvo->id}", $this->edicao($alvo, ['email' => 'outra@exemplo.com.br']))
            ->assertForbidden();

        $this->actingAs($admin)->putJson("/api/usuarios/{$alvo->id}", $this->edicao($alvo, ['name' => 'Ana Maria']))
            ->assertOk();
        $this->assertSame('Ana Maria', $alvo->fresh()->name);
    }

    public function test_super_admin_pode_trocar_o_email(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $alvo = $this->usuario($t, 'consulta', ['email' => 'ana@exemplo.com.br']);

        $this->actingAs($this->usuario(null, 'super-admin', ['tenant_id' => null]))
            ->putJson("/api/usuarios/{$alvo->id}", $this->edicao($alvo, ['email' => 'outra@exemplo.com.br']))
            ->assertOk();
    }

    public function test_usuario_nao_troca_o_proprio_email_no_perfil_mas_super_admin_pode(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $u = $this->usuario($t, 'operador', ['email' => 'ana@exemplo.com.br']);

        $this->actingAs($u)->putJson('/api/me', [
            'name' => 'Ana', 'email' => 'ana@gmail.com', 'current_password' => 'password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        // Só o nome muda: permitido.
        $this->actingAs($u)->putJson('/api/me', ['name' => 'Ana Maria', 'email' => 'ana@exemplo.com.br'])->assertOk();

        $root = $this->usuario(null, 'super-admin', ['email' => 'root@exemplo.com.br', 'tenant_id' => null]);
        $this->actingAs($root)->putJson('/api/me', [
            'name' => 'Root', 'email' => 'root2@exemplo.com.br', 'current_password' => 'password',
        ])->assertOk();
    }

    // ---- aprovação do pendente pelo admin-tenant ------------------------------

    public function test_admin_tenant_ve_o_pendente_mas_nao_um_usuario_inativo_qualquer_sem_entidade(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $pendente = $this->pendente();
        $solto = User::factory()->create(['email' => 'solto@exemplo.com.br', 'ativo' => false, 'tenant_id' => null]);
        $solto->assignRole('consulta');

        $resp = $this->actingAs($this->usuario($t, 'admin-tenant'))->getJson('/api/usuarios')->assertOk();

        $emails = collect($resp->json('data'))->pluck('email')->all();
        $this->assertContains($pendente->email, $emails);
        $this->assertNotContains($solto->email, $emails);
        $this->assertTrue(collect($resp->json('data'))->firstWhere('email', $pendente->email)['aprovacao_pendente']);
    }

    public function test_admin_tenant_aprova_o_pendente_para_a_propria_entidade(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $pendente = $this->pendente();
        $admin = $this->usuario($t, 'admin-tenant');

        $this->actingAs($admin)->putJson("/api/usuarios/{$pendente->id}", $this->edicao($pendente, [
            'papel' => 'operador', 'tenants' => [$t->id],
        ]))->assertOk();

        $pendente->refresh();
        $this->assertFalse($pendente->aprovacao_pendente);
        $this->assertTrue($pendente->ativo, 'aprovar (escolher a entidade) já ativa');
        $this->assertTrue($pendente->hasRole('operador'));
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $t->id, 'user_id' => $pendente->id]);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'usuario.pendente.vinculado']);
    }

    public function test_aprovar_sem_escolher_entidade_e_recusado_com_mensagem(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $pendente = $this->pendente();

        $this->actingAs($this->usuario($t, 'admin-tenant'))
            ->putJson("/api/usuarios/{$pendente->id}", $this->edicao($pendente, ['tenants' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenants');

        $pendente->refresh();
        $this->assertTrue($pendente->aprovacao_pendente);
        $this->assertFalse($pendente->ativo);
    }

    public function test_botao_ativar_nao_ativa_um_pendente_sem_entidade(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $pendente = $this->pendente();

        $this->actingAs($this->usuario($t, 'admin-tenant'))
            ->putJson("/api/usuarios/{$pendente->id}/ativo", ['ativo' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ativo');

        $this->assertFalse($pendente->fresh()->ativo);
    }

    public function test_admin_tenant_nao_vincula_o_pendente_a_entidade_alheia(): void
    {
        $this->ligar();
        $minha = Tenant::factory()->create();
        $alheia = Tenant::factory()->create();
        $pendente = $this->pendente();

        $this->actingAs($this->usuario($minha, 'admin-tenant'))
            ->putJson("/api/usuarios/{$pendente->id}", $this->edicao($pendente, ['tenants' => [$alheia->id]]))
            ->assertForbidden();

        $this->assertTrue($pendente->fresh()->aprovacao_pendente);
    }

    public function test_admin_tenant_nao_promove_o_pendente_a_super_admin(): void
    {
        $this->ligar();
        $t = Tenant::factory()->create();
        $pendente = $this->pendente();

        $this->actingAs($this->usuario($t, 'admin-tenant'))
            ->putJson("/api/usuarios/{$pendente->id}", $this->edicao($pendente, ['papel' => 'super-admin', 'tenants' => [$t->id]]))
            ->assertForbidden();
    }

    public function test_admin_tenant_nao_mexe_em_usuario_de_outra_entidade_que_nao_e_pendente(): void
    {
        $this->ligar();
        $minha = Tenant::factory()->create();
        $alheia = Tenant::factory()->create();
        $deOutra = $this->usuario($alheia, 'consulta');

        $this->actingAs($this->usuario($minha, 'admin-tenant'))
            ->putJson("/api/usuarios/{$deOutra->id}", $this->edicao($deOutra, ['tenants' => [$minha->id]]))
            ->assertForbidden();
    }
}

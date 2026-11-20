<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TwoFactorService;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Login com Google Workspace: opcional, desligado por padrão.
 *
 * O Google é simulado (Socialite mockado): os testes cobrem as REGRAS do
 * NFeGov (chave liga/desliga, domínio, usuário cadastrado e ativo, vínculo
 * por `sub`, segundo fator preservado), não a rede.
 */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function ligar(array $dominios = ['exemplo.com.br']): void
    {
        config([
            'nfegov.google_login.habilitado' => true,
            'nfegov.google_login.dominios' => $dominios,
            'services.google.client_id' => 'client-id-teste',
            'services.google.client_secret' => 'client-secret-teste',
            'services.google.redirect' => 'https://nfe.exemplo.test/auth/google/callback',
        ]);
    }

    private function usuario(array $atributos = [], string $papel = 'admin-tenant'): User
    {
        $tenant = Tenant::factory()->create();

        $u = User::factory()->comTenant($tenant)->create(array_merge(
            ['email' => 'maria@exemplo.com.br'],
            $atributos,
        ));
        $u->assignRole($papel);

        return $u;
    }

    /** Simula o que o Google devolve no callback. */
    private function google(array $dados = []): void
    {
        $d = array_merge([
            'sub' => 'google-sub-123',
            'email' => 'maria@exemplo.com.br',
            'email_verified' => true,
            'hd' => 'exemplo.com.br',
            'name' => 'Maria',
        ], $dados);

        $usuario = (new GoogleUser())->setRaw($d)->map([
            'id' => $d['sub'],
            'name' => $d['name'],
            'email' => $d['email'],
        ]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($usuario);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    // ---- chave liga/desliga ----------------------------------------------------

    public function test_desligado_por_padrao_rotas_dao_404_e_opcoes_dizem_false(): void
    {
        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();

        $this->getJson('/api/login/opcoes')
            ->assertOk()
            ->assertJsonPath('data.google', false);
    }

    public function test_chave_ligada_sem_credenciais_ou_dominios_continua_desligada(): void
    {
        config(['nfegov.google_login.habilitado' => true]);

        $this->getJson('/api/login/opcoes')->assertJsonPath('data.google', false);
        $this->get('/auth/google/redirect')->assertNotFound();

        $this->ligar([]); // credenciais ok, mas nenhum domínio permitido
        $this->getJson('/api/login/opcoes')->assertJsonPath('data.google', false);
    }

    public function test_ligado_e_configurado_as_opcoes_dizem_true(): void
    {
        $this->ligar();

        $this->getJson('/api/login/opcoes')
            ->assertOk()
            ->assertJsonPath('data.google', true);
    }

    public function test_opcoes_nao_expoem_credenciais_nem_dominios(): void
    {
        $this->ligar();

        $corpo = $this->getJson('/api/login/opcoes')->getContent();

        $this->assertStringNotContainsString('client-secret-teste', $corpo);
        $this->assertStringNotContainsString('client-id-teste', $corpo);
        $this->assertStringNotContainsString('exemplo.com.br', $corpo);
    }

    public function test_login_por_senha_segue_funcionando_com_o_google_desligado(): void
    {
        $u = $this->usuario(['password' => bcrypt('senha-forte')]);

        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'])
            ->assertOk();
    }

    // ---- redirecionamento ------------------------------------------------------

    public function test_redirect_vai_para_o_google_filtrando_pelo_dominio(): void
    {
        $this->ligar();

        $resposta = $this->get('/auth/google/redirect');

        $resposta->assertRedirect();
        $destino = $resposta->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $destino);
        $this->assertStringContainsString('hd=exemplo.com.br', $destino);
        $this->assertStringContainsString('prompt=select_account', $destino);
        $this->assertStringContainsString('state=', $destino);
    }

    // ---- callback: sucesso -----------------------------------------------------

    public function test_callback_loga_usuario_cadastrado_e_ativo_e_grava_o_google_id(): void
    {
        $this->ligar();
        $u = $this->usuario();
        $this->google();

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($u);
        $this->assertSame('google-sub-123', $u->fresh()->google_id);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'login.google', 'user_id' => $u->id]);
    }

    public function test_callback_nao_marca_o_segundo_fator_como_verificado(): void
    {
        $this->ligar();
        $u = $this->usuario();
        $this->google();

        $this->get('/auth/google/callback')->assertRedirect('/');

        // O MFA do NFeGov continua valendo: a sessão NÃO sai marcada como verificada.
        $this->assertFalse(session()->has(TwoFactorService::SESSAO_VERIFICADO));
        $this->assertAuthenticatedAs($u);
    }

    public function test_callback_acha_o_usuario_pelo_google_id_mesmo_com_email_diferente(): void
    {
        $this->ligar();
        $u = $this->usuario(['email' => 'antigo@exemplo.com.br', 'google_id' => 'google-sub-123']);
        $this->google(['email' => 'novo@exemplo.com.br']);

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($u);
    }

    public function test_callback_ignora_caixa_alta_no_email(): void
    {
        $this->ligar();
        $u = $this->usuario(['email' => 'Maria@EXEMPLO.com.br']);
        $this->google(['email' => 'maria@exemplo.com.br']);

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($u);
    }

    // ---- callback: negativas ---------------------------------------------------

    public function test_callback_nega_usuario_nao_cadastrado_e_nao_cria_conta(): void
    {
        $this->ligar();
        $this->google(['email' => 'desconhecido@exemplo.com.br']);
        $antes = User::withoutGlobalScopes()->count();

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
        $this->assertSame($antes, User::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['acao' => 'login.google.negado']);
    }

    public function test_callback_nega_usuario_inativo(): void
    {
        $this->ligar();
        $this->usuario(['ativo' => false]);
        $this->google();

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
    }

    public function test_callback_nega_dominio_fora_da_lista(): void
    {
        $this->ligar();
        $this->usuario(['email' => 'maria@outra.com.br']);
        $this->google(['email' => 'maria@outra.com.br', 'hd' => 'outra.com.br']);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
    }

    public function test_callback_nega_conta_pessoal_sem_claim_hd(): void
    {
        $this->ligar();
        $this->usuario(['email' => 'maria@gmail.com']);
        $this->google(['email' => 'maria@gmail.com', 'hd' => null]);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
    }

    public function test_callback_nega_email_com_dominio_diferente_do_hd(): void
    {
        $this->ligar();
        $this->usuario(['email' => 'maria@gmail.com']);
        $this->google(['email' => 'maria@gmail.com', 'hd' => 'exemplo.com.br']);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
    }

    public function test_callback_nega_email_nao_verificado(): void
    {
        $this->ligar();
        $this->usuario();
        $this->google(['email_verified' => false]);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
    }

    public function test_callback_nega_quando_o_email_ja_esta_amarrado_a_outra_conta_google(): void
    {
        $this->ligar();
        $this->usuario(['google_id' => 'outra-conta-google']);
        $this->google(['sub' => 'google-sub-123']);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertGuest();
    }

    public function test_callback_com_erro_do_google_volta_para_o_login(): void
    {
        $this->ligar();

        $this->get('/auth/google/callback?error=access_denied')
            ->assertRedirect('/login?erro=google_cancelado');

        $this->assertGuest();
    }

    public function test_callback_com_falha_na_troca_do_codigo_volta_para_o_login(): void
    {
        $this->ligar();

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('state inválido'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback?code=x&state=y')
            ->assertRedirect('/login?erro=google_falhou');

        $this->assertGuest();
    }

    public function test_motivo_detalhado_fica_so_na_auditoria_e_nao_na_url(): void
    {
        $this->ligar();
        $this->google(['email' => 'desconhecido@exemplo.com.br']);

        $resposta = $this->get('/auth/google/callback');

        $this->assertStringNotContainsString('cadastrado', (string) $resposta->headers->get('Location'));
        $this->assertDatabaseHas('audit_logs', ['acao' => 'login.google.negado']);
    }
}

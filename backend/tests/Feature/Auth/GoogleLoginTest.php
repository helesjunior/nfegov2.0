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

    // ---- cadastro pendente (NFEGOV_GOOGLE_AUTOCADASTRO=pendente) ---------------

    private function pendente(): void
    {
        $this->ligar();
        config(['nfegov.google_login.autocadastro' => 'pendente']);
    }

    private function pendentes(): \Illuminate\Database\Eloquent\Collection
    {
        return User::withoutGlobalScopes()->where('email', 'nova@exemplo.com.br')->get();
    }

    public function test_autocadastro_vem_desligado_por_padrao(): void
    {
        $this->assertFalse(\App\Support\GoogleLogin::autocadastroPendente());

        config(['nfegov.google_login.autocadastro' => 'qualquer-coisa']);
        $this->assertFalse(\App\Support\GoogleLogin::autocadastroPendente());
    }

    public function test_pendente_cria_cadastro_inativo_consulta_sem_entidade_e_nao_loga(): void
    {
        $this->pendente();
        $this->google(['email' => 'nova@exemplo.com.br', 'sub' => 'sub-nova', 'name' => 'Nova Pessoa']);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_pendente');

        $this->assertGuest();
        $u = $this->pendentes()->sole();
        $this->assertSame('Nova Pessoa', $u->name);
        $this->assertFalse($u->ativo);
        $this->assertTrue($u->aprovacao_pendente);
        $this->assertSame('sub-nova', $u->google_id);
        $this->assertNull($u->tenant_id);
        $this->assertSame(0, $u->tenants()->count());
        $this->assertSame(['consulta'], $u->getRoleNames()->all());
        $this->assertNotEmpty($u->password);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'login.google.autocadastro']);
    }

    public function test_pendente_usa_a_parte_local_do_email_quando_o_google_nao_manda_nome(): void
    {
        $this->pendente();
        $this->google(['email' => 'nova@exemplo.com.br', 'sub' => 'sub-nova', 'name' => '']);

        $this->get('/auth/google/callback');

        $this->assertSame('nova', $this->pendentes()->sole()->name);
    }

    public function test_pendente_nao_cria_para_dominio_fora_da_lista_nem_sem_hd_nem_nao_verificado(): void
    {
        $this->pendente();

        foreach ([
            ['email' => 'nova@outro.com.br', 'hd' => 'outro.com.br'],
            ['email' => 'nova@exemplo.com.br', 'hd' => ''],
            ['email' => 'nova@exemplo.com.br', 'email_verified' => false],
            ['email' => 'nova@gmail.com', 'hd' => 'exemplo.com.br'],
        ] as $caso) {
            $this->google($caso + ['sub' => 'sub-x']);
            $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');
        }

        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    public function test_pendente_segunda_tentativa_nao_duplica_e_continua_negada(): void
    {
        $this->pendente();
        $this->google(['email' => 'nova@exemplo.com.br', 'sub' => 'sub-nova']);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_pendente');
        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_nao_autorizado');

        $this->assertCount(1, $this->pendentes());
        $this->assertGuest();
    }

    public function test_pendente_usuario_ja_cadastrado_entra_normalmente_sem_criar_outro(): void
    {
        $this->pendente();
        $this->usuario();
        $this->google();

        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertSame(1, User::withoutGlobalScopes()->count());
    }

    public function test_pendente_depois_de_aprovado_e_vinculado_a_pessoa_consegue_entrar(): void
    {
        $this->pendente();
        $this->google(['email' => 'nova@exemplo.com.br', 'sub' => 'sub-nova']);
        $this->get('/auth/google/callback');

        // O administrador ativa e vincula a uma entidade.
        $u = $this->pendentes()->sole();
        $tenant = Tenant::factory()->create();
        $u->update(['ativo' => true, 'tenant_id' => $tenant->id]);
        $u->tenants()->sync([$tenant->id]);

        $this->get('/auth/google/callback')->assertRedirect('/');
        $this->assertAuthenticatedAs($u->fresh());
    }

    public function test_pendente_falha_na_criacao_volta_para_o_login_sem_vazar_detalhe(): void
    {
        $this->pendente();
        // Papel inexistente: a criação precisa ser desfeita por inteiro.
        \Spatie\Permission\Models\Role::where('name', 'consulta')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->google(['email' => 'nova@exemplo.com.br', 'sub' => 'sub-nova']);

        $this->get('/auth/google/callback')->assertRedirect('/login?erro=google_falhou');

        $this->assertCount(0, $this->pendentes());
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

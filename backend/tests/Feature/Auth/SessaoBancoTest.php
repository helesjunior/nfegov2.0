<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regressão do 401 em produção: com SESSION_DRIVER=database e o Origin do SPA
 * em SANCTUM_STATEFUL_DOMAINS, a sessão era iniciada duas vezes (grupo `web` +
 * statefulApi) e nunca era gravada — a partir da 2ª requisição, 401.
 */
class SessaoBancoTest extends TestCase
{
    use RefreshDatabase;

    public function test_rotas_da_api_nao_iniciam_a_sessao_duas_vezes(): void
    {
        // O grupo `web` do routes/api.php já inicia a sessão; o middleware
        // stateful do Sanctum (statefulApi) faria isso de novo.
        $router = $this->app['router'];
        $rota = $router->getRoutes()->match(\Illuminate\Http\Request::create('/api/me', 'GET'));
        $pilha = $router->gatherRouteMiddleware($rota);

        $this->assertNotContains(\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, $pilha);
        $this->assertCount(1, array_filter($pilha, fn ($m) => $m === \Illuminate\Session\Middleware\StartSession::class));
    }

    public function test_sessao_em_banco_sobrevive_a_varias_requisicoes_do_spa(): void
    {
        config([
            'session.driver' => 'database',
            'sanctum.stateful' => ['nfe.example.test'],
        ]);
        $this->seed(\Database\Seeders\RolesSeeder::class);

        $u = User::factory()->comTenant(Tenant::factory()->create())->create([
            'password' => Hash::make('senha-forte'),
        ]);
        $u->assignRole('admin-tenant');

        $headers = ['Origin' => 'https://nfe.example.test', 'Referer' => 'https://nfe.example.test/'];
        $nomeCookie = config('session.cookie');

        $login = $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'], $headers)
            ->assertOk();
        $cookie = $login->getCookie($nomeCookie, false)->getValue();

        // Como o navegador: cada requisição só leva o cookie que recebeu por
        // último, e o guard é resolvido de novo a partir da sessão.
        for ($i = 1; $i <= 3; $i++) {
            $this->app['auth']->forgetGuards();

            $resp = $this->call('GET', '/api/me', [], [$nomeCookie => $cookie], [], $this->transformHeadersToServerVars($headers + ['Accept' => 'application/json']));
            $resp->assertOk();

            $novo = $resp->getCookie($nomeCookie, false);
            if ($novo) {
                $cookie = $novo->getValue();
            }
        }
    }
}

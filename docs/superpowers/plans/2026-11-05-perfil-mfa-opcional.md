# Área de perfil do usuário e MFA opcional — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar a área "Minha conta" onde o usuário logado atualiza nome/e-mail/senha e habilita/desabilita o MFA (quando não for obrigado pelo papel).

**Architecture:** Rotas dedicadas de self (`PUT /api/me`, `PUT /api/me/password`, `DELETE /api/me/mfa`) no grupo de sessão `auth:sanctum`+`tenant` (sem o middleware `mfa`), servidas por um `ProfileController` novo com `FormRequest`s próprios. A representação do usuário (`dadosUsuario`) é extraída de `AuthController` para um `UserPresenter` reutilizado por ambos e passa a expor `mfa_obrigatorio`. No SPA, uma feature `perfil` com a tela `/minha-conta`, acessada pelo nome do usuário no topbar.

**Tech Stack:** Laravel 13 (PHP 8.4), Sanctum (cookie session), Spatie permissions, pragmarx/google2fa; React 19 + Vite + TanStack Query + react-router; testes PHPUnit (backend) e Vitest/RTL (frontend).

**Spec:** `docs/superpowers/specs/2026-11-05-perfil-mfa-opcional-design.md`

## Global Constraints

- Conversar sempre em português do Brasil (comentários e mensagens de UI em pt-BR).
- Multitenancy: banco único, `tenant_id` + global scope; não alterar esse modelo.
- MFA obrigatório por papel: `config('nfegov.mfa.papeis_obrigatorios')` = `['super-admin','admin-tenant']` — **não** alterar.
- Papéis canônicos: `super-admin`, `admin-tenant`, `operador`, `consulta`.
- Testes backend: `docker compose run --rm app php artisan test` (dentro de `nfegov2.0`).
- Testes frontend: em `frontend/`, `npm run test`; build: `npm run build` (gera `backend/public/spa/`).
- Model único disponível: `assefaz/DeepSeek-V4.1-Flash` — não especificar `model` em subagentes.
- Avisar o parceiro antes de qualquer `git push` (nesta tarefa já está autorizado o push ao final).
- `docker compose` e `git push` no PowerShell retornam exit code 1 como ruído mesmo em sucesso — confiar na saída real.

## Review Focus

- **E-mail igual ao atual (case-insensitive):** trocar apenas nome não deve exigir senha atual; mudar o e-mail (inclusive só a caixa, ex.: `Admin@x` → `admin@x`) deve exigir. O teste da Task 1 cobre a igualdade exata.
- **Desabilitar MFA sendo papel obrigatório:** mesmo com um código TOTP válido, deve retornar 403 e **não** limpar o segredo. O teste da Task 4 cobre.
- **Rota de perfil para quem ainda não confirmou o MFA:** `operador`/quem tem `mfa_pendente=false` e super-admin pendente devem conseguir acessar `GET`/`PUT` de perfil (as rotas ficam fora do middleware `mfa`). O teste da Task 2 verifica acesso de admin-tenant sem MFA.
- **Senha: confirmação divergente ou fraca:** deve falhar com 422 no campo `password`. Coberto na Task 3.
- **SPA: exposição indevida do botão "Desabilitar MFA":** para papel obrigatório o botão não aparece; para não-obrigatório com MFA ativo, aparece. Coberto na Task 7.

---

### Task 1: `UserPresenter` + flag `mfa_obrigatorio` no `/me`

Extrai a montagem do usuário para um serviço e expõe se o papel exige MFA.

**Files:**
- Create: `backend/app/Services/UserPresenter.php`
- Modify: `backend/app/Http/Controllers/Api/AuthController.php` (substitui o método privado `dadosUsuario` por chamada ao presenter)
- Test: `backend/tests/Feature/Auth/MePresenterTest.php`

**Interfaces:**
- Consumes: `TwoFactorService` (`confirmado(User): bool`), `TenantAtivoResolver` (`paraUsuario(User, ?int): ?int`, `podeTodas(User): bool`, `disponiveis(User): Collection`), config `nfegov.mfa.papeis_obrigatorios`.
- Produces: `UserPresenter::paraUsuario(User $user, ?int $preferenciaTenant = null): array` retornando as chaves `id, name, email, tenant_id, tenant_ativo, tenants_disponiveis, pode_todas, papeis, permissoes, mfa_obrigatorio, mfa_pendente, mfa_confirmado`. Usado por `AuthController` (Task 1) e `ProfileController` (Tasks 2–4).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MePresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuario(array $papeis): User
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papeis);

        return $u;
    }

    public function test_me_expoe_mfa_obrigatorio_para_papel_administrativo(): void
    {
        $u = $this->usuario(['admin-tenant']);

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.mfa_obrigatorio', true);
    }

    public function test_me_nao_obriga_mfa_para_operador(): void
    {
        $u = $this->usuario(['operador']);

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.mfa_obrigatorio', false)
            ->assertJsonPath('user.mfa_pendente', false);
    }
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `docker compose run --rm app php artisan test --filter=MePresenterTest`
Expected: FAIL — `user.mfa_obrigatorio` ausente (assertJsonPath recebe null).

- [ ] **Step 3: Criar `UserPresenter`**

Crie `backend/app/Services/UserPresenter.php`. O corpo é exatamente o antigo `dadosUsuario()`, movido, acrescido de `mfa_obrigatorio`:

```php
<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

/**
 * Representação segura do usuário para a SPA (sem senha/segredo).
 *
 * Fonte única usada por /login, /me e pelas rotas de perfil, para que a SPA
 * receba sempre o mesmo formato e não reimplemente regras (ex.: quem exige MFA).
 */
class UserPresenter
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TenantAtivoResolver $resolver,
    ) {
    }

    public function paraUsuario(User $user, ?int $preferenciaTenant = null): array
    {
        $exigeMfa = $user->hasAnyRole(config('nfegov.mfa.papeis_obrigatorios', ['super-admin', 'admin-tenant']));

        $tenantAtivoId = $this->resolver->paraUsuario($user, $preferenciaTenant);
        $tenantAtivo = $tenantAtivoId ? Tenant::find($tenantAtivoId) : null;
        $disponiveis = $this->resolver->disponiveis($user)->map(fn ($t) => ['id' => $t->id, 'nome' => $t->nome]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'tenant_ativo' => $tenantAtivo ? ['id' => $tenantAtivo->id, 'nome' => $tenantAtivo->nome] : null,
            'tenants_disponiveis' => $disponiveis,
            'pode_todas' => $this->resolver->podeTodas($user),
            'papeis' => $user->getRoleNames(),
            'permissoes' => $user->getAllPermissions()->pluck('name'),
            'mfa_obrigatorio' => $exigeMfa,
            'mfa_pendente' => $exigeMfa && ! $this->twoFactor->confirmado($user),
            'mfa_confirmado' => $this->twoFactor->confirmado($user),
        ];
    }
}
```

- [ ] **Step 4: Usar o presenter no `AuthController`**

Em `backend/app/Http/Controllers/Api/AuthController.php`: injetar `UserPresenter` no construtor e substituir as chamadas `$this->dadosUsuario($user)` por `$this->presenter->paraUsuario($user, $preferencia)`. O `AuthController` hoje calcula a preferência dentro de `dadosUsuario()` lendo a sessão; mova essa leitura para o controller (método privado `preferenciaTenant(): ?int` que faz `request()->session()?->get('tenant_id')` e converte para int|null). Remova o método privado `dadosUsuario()`.

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `docker compose run --rm app php artisan test --filter='MePresenterTest|MfaTest|LoginTest'`
Expected: PASS (todos).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Services/UserPresenter.php backend/app/Http/Controllers/Api/AuthController.php backend/tests/Feature/Auth/MePresenterTest.php
git commit -m "refactor(api): extrai UserPresenter e expoe mfa_obrigatorio no /me"
```

---

### Task 2: `PUT /api/me` — atualizar nome e e-mail

**Files:**
- Create: `backend/app/Http/Requests/UpdateProfileRequest.php`
- Create: `backend/app/Http/Controllers/Api/ProfileController.php` (método `update`)
- Modify: `backend/routes/api.php` (adiciona rotas no grupo `auth:sanctum`+`tenant`)
- Test: `backend/tests/Feature/Api/ProfileApiTest.php`

**Interfaces:**
- Consumes: `UserPresenter::paraUsuario(User, ?int): array` (Task 1); `User` (fillable `name`,`email`).
- Produces: `PUT /api/me` → 200 `{ user: {...} }` (formato do presenter). Regras de validação em `UpdateProfileRequest`. `ProfileController` será estendido nas Tasks 3–4.

- [ ] **Step 1: Escrever o teste que falha**

```php
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
}
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `docker compose run --rm app php artisan test --filter=ProfileApiTest`
Expected: FAIL — rota `PUT /api/me` não existe (404).

- [ ] **Step 3: Criar `UpdateProfileRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $user = $this->user();
        $emailMudou = strtolower((string) $this->input('email')) !== strtolower((string) $user->email);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => [Rule::requiredIf($emailMudou), 'nullable', 'current_password'],
        ];
    }
}
```

(O `current_password` rule do Laravel valida contra o guard padrão; se o projeto usar guard `web`, é o esperado.)

- [ ] **Step 4: Criar `ProfileController::update` e a rota**

`backend/app/Http/Controllers/Api/ProfileController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Área de perfil do usuário logado ("Minha conta").
 *
 * Fica no grupo de sessão (sem o middleware `mfa`) para que quem ainda não
 * confirmou o segundo fator consiga acessar a própria área.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly UserPresenter $presenter)
    {
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();

        return response()->json(['user' => $this->presenter->paraUsuario($user->fresh(), $this->preferencia($request))]);
    }

    private function preferencia(Request $request): ?int
    {
        $valor = $request->session()?->get('tenant_id');

        return $valor === null ? null : (int) $valor;
    }
}
```

Em `backend/routes/api.php`, dentro do grupo `Route::middleware(['auth:sanctum', 'tenant'])` (o mesmo de `/me`), adicione:

```php
Route::put('/me', [\App\Http\Controllers\Api\ProfileController::class, 'update'])->name('api.me.update');
```

> Atenção: `$request->validated()` em `UpdateProfileRequest` inclui `current_password`; `$user->fill()` ignora campos não-fillable (`current_password` não é fillable), então não há risco. Mas para clareza, use `$user->fill($request->safe()->only(['name', 'email']))`.

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `docker compose run --rm app php artisan test --filter=ProfileApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Requests/UpdateProfileRequest.php backend/app/Http/Controllers/Api/ProfileController.php backend/routes/api.php backend/tests/Feature/Api/ProfileApiTest.php
git commit -m "feat(api): PUT /api/me atualiza nome e email do proprio usuario"
```

---

### Task 3: `PUT /api/me/password` — trocar a senha

**Files:**
- Create: `backend/app/Http/Requests/UpdatePasswordRequest.php`
- Modify: `backend/app/Http/Controllers/Api/ProfileController.php` (método `updatePassword`)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/ProfileApiTest.php` (acrescenta casos)

**Interfaces:**
- Consumes: `ProfileController::preferencia()` (Task 2); `Hash`; `Illuminate\Validation\Rules\Password`.
- Produces: `PUT /api/me/password` → 200 `{ user: {...} }`. Sessão permanece válida.

- [ ] **Step 1: Escrever o teste que falha**

Acrescente a `ProfileApiTest`:

```php
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
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `docker compose run --rm app php artisan test --filter=ProfileApiTest`
Expected: FAIL — rota `PUT /api/me/password` não existe.

- [ ] **Step 3: Criar `UpdatePasswordRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
```

- [ ] **Step 4: Implementar `ProfileController::updatePassword` e a rota**

Adicione em `ProfileController`:

```php
    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        // O cast `password => hashed` do model aplica o hash ao atribuir.
        $user->fill(['password' => $request->validated('password')])->save();

        return response()->json(['user' => $this->presenter->paraUsuario($user->fresh(), $this->preferencia($request))]);
    }
```

> O model `User` tem cast `'password' => 'hashed'`, portanto atribuir texto puro já persiste o hash. Não use `Hash::make` em duplicidade.

Em `routes/api.php`, no grupo de sessão:

```php
Route::put('/me/password', [\App\Http\Controllers\Api\ProfileController::class, 'updatePassword'])->name('api.me.password');
```

Inclua o `use App\Http\Requests\UpdatePasswordRequest;` no controller.

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `docker compose run --rm app php artisan test --filter=ProfileApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Requests/UpdatePasswordRequest.php backend/app/Http/Controllers/Api/ProfileController.php backend/routes/api.php backend/tests/Feature/Api/ProfileApiTest.php
git commit -m "feat(api): PUT /api/me/password troca a senha exigindo a atual"
```

---

### Task 4: `DELETE /api/me/mfa` — desabilitar MFA (não-obrigatórios)

**Files:**
- Create: `backend/app/Http/Requests/DisableMfaRequest.php`
- Modify: `backend/app/Http/Controllers/Api/ProfileController.php` (método `destroyMfa`)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/ProfileApiTest.php` (acrescenta casos)

**Interfaces:**
- Consumes: `TwoFactorService::verify(User, string): bool`, `TwoFactorService::confirmado(User): bool`; config `nfegov.mfa.papeis_obrigatorios`; `ProfileController::preferencia()`.
- Produces: `DELETE /api/me/mfa` → 200 `{ user: {...} }` com `mfa_confirmado=false`; 403 se papel obrigatório; 422 se `code` inválido ou MFA não confirmado.

- [ ] **Step 1: Escrever o teste que falha**

Acrescente a `ProfileApiTest`:

```php
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
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run: `docker compose run --rm app php artisan test --filter=ProfileApiTest`
Expected: FAIL — rota `DELETE /api/me/mfa` não existe.

- [ ] **Step 3: Criar `DisableMfaRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisableMfaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string']];
    }
}
```

- [ ] **Step 4: Implementar `ProfileController::destroyMfa` e a rota**

Adicione em `ProfileController` (injete `TwoFactorService` no construtor):

```php
    public function destroyMfa(DisableMfaRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->hasAnyRole(config('nfegov.mfa.papeis_obrigatorios', []))) {
            abort(403, 'Seu perfil exige MFA; não é possível desabilitar.');
        }

        if (! $twoFactor->confirmado($user)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => 'O MFA não está habilitado.',
            ]);
        }

        if (! $twoFactor->verify($user, $request->validated('code'))) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'code' => 'Código inválido.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'mfa_recent_at' => null,
        ])->save();

        return response()->json(['user' => $this->presenter->paraUsuario($user->fresh(), $this->preferencia($request))]);
    }
```

Em `routes/api.php`, no grupo de sessão:

```php
Route::delete('/me/mfa', [\App\Http\Controllers\Api\ProfileController::class, 'destroyMfa'])->name('api.me.mfa.destroy');
```

- [ ] **Step 5: Rodar o teste e confirmar que passa**

Run: `docker compose run --rm app php artisan test --filter=ProfileApiTest`
Expected: PASS.

- [ ] **Step 6: Rodar a suíte backend completa**

Run: `docker compose run --rm app php artisan test`
Expected: PASS (191 anteriores + novos).

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Requests/DisableMfaRequest.php backend/app/Http/Controllers/Api/ProfileController.php backend/routes/api.php backend/tests/Feature/Api/ProfileApiTest.php
git commit -m "feat(api): DELETE /api/me/mfa desabilita o MFA de quem nao e obrigado"
```

---

### Task 5: API client do SPA — `perfilApi` e `mfa_obrigatorio`

**Files:**
- Modify: `frontend/src/lib/api.ts` (tipo `Usuario` + `perfilApi`)
- Test: `frontend/src/lib/api.test.ts` (cria, se não existir; senão acrescenta)

**Interfaces:**
- Consumes: `http` (axios) já configurado.
- Produces: `Usuario.mfa_obrigatorio: boolean`; `perfilApi.atualizar({ name, email, current_password? })`, `perfilApi.trocarSenha({ current_password, password, password_confirmation })`, `perfilApi.desabilitarMfa(code)`. Todas retornam `Usuario`.

- [ ] **Step 1: Escrever o teste que falha**

Se não existir `frontend/src/lib/api.test.ts`, crie com foco no client de perfil (mock do `http`):

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'

const putMock = vi.fn()
const deleteMock = vi.fn()
vi.mock('axios', async () => {
  const actual = await vi.importActual<typeof import('axios')>('axios')
  const instance = {
    get: vi.fn(),
    post: vi.fn(),
    put: (...a: unknown[]) => putMock(...a),
    delete: (...a: unknown[]) => deleteMock(...a),
    interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
    defaults: { headers: {} },
  }
  return { ...actual, default: { ...actual.default, create: () => instance } }
})

import { perfilApi } from './api'

describe('perfilApi', () => {
  beforeEach(() => {
    putMock.mockReset().mockResolvedValue({ data: { user: { id: 1, name: 'N', email: 'e@e' } } })
    deleteMock.mockReset().mockResolvedValue({ data: { user: { id: 1, name: 'N', email: 'e@e', mfa_confirmado: false } } })
  })

  it('atualizar chama PUT /me e devolve o usuário', async () => {
    const u = await perfilApi.atualizar({ name: 'N', email: 'e@e' })
    expect(putMock).toHaveBeenCalledWith('/me', { name: 'N', email: 'e@e' })
    expect(u.name).toBe('N')
  })

  it('trocarSenha chama PUT /me/password', async () => {
    await perfilApi.trocarSenha({ current_password: 'a', password: 'b', password_confirmation: 'b' })
    expect(putMock).toHaveBeenCalledWith('/me/password', {
      current_password: 'a', password: 'b', password_confirmation: 'b',
    })
  })

  it('desabilitarMfa chama DELETE /me/mfa com o código', async () => {
    await perfilApi.desabilitarMfa('123456')
    expect(deleteMock).toHaveBeenCalledWith('/me/mfa', { data: { code: '123456' } })
  })
})
```

> Se o mock de axios divergir do padrão do projeto, siga o padrão que já existe nos testes de `lib/api` do repositório. Verifique primeiro se há `api.test.ts`; se houver, apenas acrescente os casos.

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run (em `frontend/`): `npm run test -- api.test`
Expected: FAIL — `perfilApi` não existe.

- [ ] **Step 3: Implementar no `api.ts`**

Adicione `mfa_obrigatorio: boolean` à interface `Usuario` (após `mfa_pendente`). Crie:

```ts
// ---- Perfil do usuário ------------------------------------------------------

export interface DadosPerfil {
  name: string
  email: string
  current_password?: string
}

export interface TrocaSenha {
  current_password: string
  password: string
  password_confirmation: string
}

export const perfilApi = {
  async atualizar(payload: DadosPerfil): Promise<Usuario> {
    const { data } = await http.put<{ user: Usuario }>('/me', payload)
    return data.user
  },

  async trocarSenha(payload: TrocaSenha): Promise<Usuario> {
    const { data } = await http.put<{ user: Usuario }>('/me/password', payload)
    return data.user
  },

  async desabilitarMfa(code: string): Promise<Usuario> {
    const { data } = await http.delete<{ user: Usuario }>('/me/mfa', { data: { code } })
    return data.user
  },
}
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `npm run test -- api.test`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/lib/api.ts frontend/src/lib/api.test.ts
git commit -m "feat(spa): cliente perfilApi e flag mfa_obrigatorio no Usuario"
```

---

### Task 6: Tela "Minha conta" (`/minha-conta`) + rota

**Files:**
- Create: `frontend/src/features/perfil/MinhaContaPage.tsx`
- Modify: `frontend/src/app/router.tsx` (import + rota filha `minha-conta`)
- Test: `frontend/src/features/perfil/MinhaContaPage.test.tsx`

**Interfaces:**
- Consumes: `perfilApi` (Task 5), `mfaApi` (já existe), `useSessao()` (`usuario`, `recarregar`), `mensagemDeErro`.
- Produces: componente `MinhaContaPage` (default export nomeado) renderizado em `/minha-conta`.

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const atualizarMock = vi.fn()
const trocarSenhaMock = vi.fn()
const desabilitarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  perfilApi: {
    atualizar: (...a: unknown[]) => atualizarMock(...a),
    trocarSenha: (...a: unknown[]) => trocarSenhaMock(...a),
    desabilitarMfa: (...a: unknown[]) => desabilitarMock(...a),
  },
  mfaApi: { setup: vi.fn(), confirm: vi.fn() },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

const usuarioBase = {
  id: 1, name: 'Fulano', email: 'f@x', tenant_id: null, tenant_ativo: null,
  tenants_disponiveis: [], pode_todas: false, papeis: ['operador'], permissoes: [],
  mfa_pendente: false, mfa_confirmado: true, mfa_obrigatorio: false,
}
let usuarioAtual = usuarioBase
vi.mock('../auth/session', () => ({
  useSessao: () => ({ usuario: usuarioAtual, recarregar: vi.fn() }),
}))

import { MinhaContaPage } from './MinhaContaPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MinhaContaPage />
    </QueryClientProvider>,
  )
}

describe('MinhaContaPage', () => {
  beforeEach(() => {
    atualizarMock.mockReset().mockResolvedValue(usuarioBase)
    trocarSenhaMock.mockReset().mockResolvedValue(usuarioBase)
    desabilitarMock.mockReset().mockResolvedValue({ ...usuarioBase, mfa_confirmado: false })
  })

  it('mostra campo de senha atual apenas quando o e-mail muda', async () => {
    const user = userEvent.setup()
    renderizar()

    expect(screen.queryByLabelText(/senha atual/i)).not.toBeInTheDocument()

    const email = screen.getByLabelText(/e-mail/i)
    await user.clear(email)
    await user.type(email, 'novo@x')

    expect(await screen.findByLabelText(/senha atual/i)).toBeInTheDocument()
  })

  it('NÃO mostra botão de desabilitar MFA quando o papel é obrigatório', () => {
    usuarioAtual = { ...usuarioBase, papeis: ['admin-tenant'], mfa_obrigatorio: true }
    renderizar()
    expect(screen.queryByRole('button', { name: /desabilitar/i })).not.toBeInTheDocument()
    usuarioAtual = usuarioBase
  })

  it('mostra botão de desabilitar MFA para quem não é obrigado e envia o código', async () => {
    const user = userEvent.setup()
    renderizar()

    const btn = screen.getByRole('button', { name: /desabilitar/i })
    await user.click(btn)

    const campo = await screen.findByLabelText(/código/i)
    await user.type(campo, '123456')
    await user.click(screen.getByRole('button', { name: /confirmar desabilita/i }))

    await waitFor(() => expect(desabilitarMock).toHaveBeenCalledWith('123456'))
  })
})
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run (em `frontend/`): `npm run test -- MinhaContaPage`
Expected: FAIL — módulo/`MinhaContaPage` não existe.

- [ ] **Step 3: Implementar `MinhaContaPage.tsx`**

Três seções em cartões. Comportamentos exigidos pelos testes:
- Campo "Senha atual" (label que casa `/senha atual/i`) na seção **Dados pessoais** aparece **somente** quando `email !== usuario.email` (comparação direta de string).
- Seção **Segurança**:
  - `usuario.mfa_obrigatorio` true → mostra texto de status "Ativo (exigido pelo seu perfil)" e **nenhum** botão "Desabilitar". Se `mfa_pendente`, oferece o fluxo de ativar (reusa `mfaApi.setup`/`confirm`, como na `MfaPage`).
  - `mfa_obrigatorio` false e `mfa_confirmado` true → botão "Desabilitar MFA"; ao clicar, revela um campo com label contendo "Código" e um botão "Confirmar desabilita" que chama `perfilApi.desabilitarMfa(code)`.
  - `mfa_obrigatorio` false e `mfa_confirmado` false → botão "Ativar MFA" (setup → mostra segredo/QR → confirm com código).
- Após cada mutação bem-sucedida: `await recarregar()` (do `useSessao`) e uma mensagem de sucesso (`role="status"`).

Use as classes já existentes (`card`, `campo`, `botao`, `botao--secundario`, `alerta alerta--erro`). Estrutura de referência: `LoginPage.tsx`/`MfaPage.tsx`.

Em `router.tsx`: importe `MinhaContaPage` e adicione como filha do `AppShell`:

```tsx
{ path: 'minha-conta', element: <MinhaContaPage /> },
```

- [ ] **Step 4: Rodar o teste e confirmar que passa**

Run: `npm run test -- MinhaContaPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/perfil/MinhaContaPage.tsx frontend/src/features/perfil/MinhaContaPage.test.tsx frontend/src/app/router.tsx
git commit -m "feat(spa): tela Minha conta com dados, senha e MFA opcional"
```

---

### Task 7: Acesso à "Minha conta" pelo topbar

**Files:**
- Modify: `frontend/src/components/layout/Topbar.tsx` (nome do usuário vira link para `/minha-conta`)
- Modify: `frontend/src/features/usuarios/UsuariosPage.test.tsx` e `frontend/src/features/sessao/SeletorEntidade.test.tsx` **apenas se** o mock de usuário precisar do novo campo (adicionar `mfa_obrigatorio: false`) para o TypeScript compilar.
- Test: `frontend/src/components/layout/Topbar.test.tsx` (cria)

**Interfaces:**
- Consumes: `react-router-dom` (`Link`), prop `usuario: Usuario`.
- Produces: o nome do usuário passa a ser um `Link` para `/minha-conta` com `aria-label` acessível.

- [ ] **Step 1: Escrever o teste que falha**

```tsx
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi } from 'vitest'
vi.mock('../../lib/api', () => ({ authApi: { logout: vi.fn() } }))
vi.mock('../../features/sessao/SeletorEntidade', () => ({ SeletorEntidade: () => null }))

import { Topbar } from './Topbar'

const usuario = {
  id: 1, name: 'Fulano', email: 'f@x', tenant_id: null, tenant_ativo: null,
  tenants_disponiveis: [], pode_todas: false, papeis: ['operador'], permissoes: [],
  mfa_pendente: false, mfa_confirmado: true, mfa_obrigatorio: false,
}

describe('Topbar', () => {
  it('o nome do usuário é um link para Minha conta', () => {
    const qc = new QueryClient()
    render(
      <QueryClientProvider client={qc}>
        <MemoryRouter>
          <Topbar usuario={usuario} />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    const link = screen.getByRole('link', { name: /minha conta/i })
    expect(link).toHaveAttribute('href', '/minha-conta')
  })
})
```

- [ ] **Step 2: Rodar o teste e confirmar que falha**

Run (em `frontend/`): `npm run test -- Topbar`
Expected: FAIL — não há link "Minha conta".

- [ ] **Step 3: Implementar no `Topbar.tsx`**

Envolva o `<strong>{usuario.name}</strong>` em `<Link to="/minha-conta" aria-label="Minha conta">{usuario.name}</Link>` (import `Link` de `react-router-dom`). Mantenha o botão "Sair".

- [ ] **Step 4: Rodar os testes e confirmar que passam**

Run: `npm run test`
Expected: PASS (todos). Se algum teste falhar por falta de `mfa_obrigatorio` no mock de `Usuario`, acrescente o campo.

- [ ] **Step 5: Build**

Run: `npm run build`
Expected: build OK, gera `backend/public/spa/`.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/layout/Topbar.tsx frontend/src/components/layout/Topbar.test.tsx
git commit -m "feat(spa): acesso a Minha conta pelo nome do usuario no topbar"
```

---

### Task 8: Verificação final, documentação e push

**Files:**
- Modify: `.superpowers/sdd/2026-11-04-cruds-multitenancy/progress.md` (registra o ciclo)

**Interfaces:** nenhuma (tarefa de verificação).

- [ ] **Step 1: Suíte backend completa**

Run (em `nfegov2.0/`): `docker compose run --rm app php artisan test`
Expected: PASS (todos).

- [ ] **Step 2: Suíte + build frontend**

Run (em `frontend/`): `npm run test` e `npm run build`
Expected: PASS e build OK.

- [ ] **Step 3: Verificação manual no navegador**

Subir a stack (`docker compose up -d postgres redis app nginx`), abrir `http://localhost:8080`, logar como `operador@agu.gov.br`/`senha123` (seed `backend/tools/seed_demo.php`):
- abrir "Minha conta" pelo nome no topo;
- trocar o nome (não pede senha) e o e-mail (pede senha);
- trocar a senha e confirmar que continua logado;
- ativar o MFA (QR + código) e depois desabilitar (pede código);
- logar como `admin@agu.gov.br`/`senha123` e conferir que **não** há botão de desabilitar MFA.

- [ ] **Step 4: Atualizar o ledger de progresso**

Registre em `.superpowers/sdd/2026-11-04-cruds-multitenancy/progress.md` o ciclo da feature de perfil: escopo, arquivos, comandos, resultados e o hash do commit final.

- [ ] **Step 5: Push (autorizado pelo parceiro)**

```bash
git push origin main
```

Expected: confirmação `... main -> main`. Conferir `git rev-parse origin/main HEAD` (iguais) e `git log --oneline origin/main..HEAD` (vazio).
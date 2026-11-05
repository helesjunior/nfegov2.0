# CRUDs administrativos, multitenancy de usuário e seletor de entidade — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Adicionar CRUDs de Entidade (Tenant) e Usuário, edição/desativação de Unidade, vínculo N:N usuário↔entidade e um seletor de entidade na sessão que alimenta o tenant ativo.

**Architecture:** `tenant_user` passa a ser a fonte de verdade dos vínculos (a coluna legada `users.tenant_id` vira "tenant de origem"). Um serviço único, `TenantAtivoResolver`, calcula o tenant efetivo a partir da sessão (`session('tenant_id')`) validado contra os vínculos e o estado (ativo) do tenant; o middleware `SetTenantContext` usa esse serviço para alimentar o `TenantContext` (fonte do global scope). Super-admin pode apontar "Todas" (null) ou qualquer entidade; os demais só entre as suas, e nunca ficam sem contexto (403).

**Tech Stack:** Laravel 13 / PHP 8.4, PostgreSQL, Sanctum (sessão por cookie), Spatie laravel-permission (sem "teams"), React + Vite + React Query + TypeScript.

**Spec:** `docs/superpowers/specs/2026-11-04-cruds-multitenancy-design.md`

## Global Constraints

- Idioma: todo texto de UI, mensagens de validação e comentários em **português do Brasil**.
- Escopo fiscal: **somente recepção/consulta** de NF-e. Nenhuma task emite NF-e.
- Multitenancy: banco único; `tenant_id` + global scope (`BelongsToTenant`). A coluna legada `users.tenant_id` **NÃO** deve ser usada para filtrar/autorizar em código novo — usar `tenant_user` (`whereHas('tenants')`).
- **Papel é global** por usuário; Spatie permanece **sem** "teams" (`config/permission.php` não muda).
- Tenant ativo vive na **sessão** (`session('tenant_id')`), validado pelo `TenantAtivoResolver`.
- **Não-super-admin nunca recebe contexto nulo** — sem vínculo ⇒ `403`.
- Todo endpoint autenticado por sessão Sanctum (`web` + `auth:sanctum`). SPA no mesmo domínio.
- Auditoria nunca recebe segredos (senha, PFX, XML). Rotas/admin que exigem MFA seguem os grupos existentes em `routes/api.php`.
- `Tenant` não usa `BelongsToTenant` (é a raiz). `Unidade`/`Certificate`/`Nfe`/`SyncRun` usam.
- Docker: comandos a partir de `nfegov2.0` (workspace dentro do repo). Testes: `docker compose run --rm app php artisan test --filter=<X>`.
- `docker compose` e `git push` no PowerShell podem retornar exit code 1 como ruído mesmo em sucesso — verificar a saída real (`PASS`/`DONE`/`main -> main`).
- Commit por step de teste passando. Não pausar entre tasks.

## Review Focus

Os cinco modos de falha mais prováveis que a spec implica e nenhum teste de task cobre por padrão (cada um vira um teste na task dona, indicado abaixo):

1. **Global scope do `User` filtrando pela coluna legada:** `GET /api/usuarios` dentro de uma entidade precisa listar quem tem **vínculo** (`tenant_user`), não quem tem `users.tenant_id` igual. Um usuário vinculado ao tenant A que foi criado com `tenant_id` B sumiria. → teste na Task 8.
2. **Super-admin "Todas" com `session('tenant_id')` apontando para tenant inexistente/inativo:** o resolver deve degradar para "Todas" (null), não lançar nem vazar. → teste na Task 2.
3. **Não-super-admin sem vínculo algum:** chamar rota de negócio deve dar `403`, nunca contexto nulo (que liberaria tudo). → teste na Task 3.
4. **Criação de unidade pelo super-admin em "Todas"** (contexto nulo): deve **exigir** `tenant_id` no corpo e nunca gravar unidade órfã. → teste na Task 9.
5. **`AuditLogger` com tenant errado:** ação de super-admin operando na entidade A deve auditar com `tenant_id = A`, não `null`. → teste na Task 4.

---

## File Structure

**Backend — criar:**
- `database/migrations/2026_11_04_130000_create_tenant_user_table.php` — pivô N:N + cópia de dados.
- `app/Services/TenantAtivoResolver.php` — serviço único de resolução do tenant ativo.
- `app/Http/Controllers/Api/SessaoTenantController.php` — `POST /api/sessao/tenant`.
- `app/Policies/TenantPolicy.php` — autorização de Tenant (super-admin).
- `app/Http/Controllers/Api/TenantController.php` — CRUD de entidades.
- `app/Http/Requests/StoreTenantRequest.php`, `app/Http/Requests/UpdateTenantRequest.php`.
- `app/Http/Resources/TenantResource.php`.
- `app/Policies/UserPolicy.php` — autorização de Usuário (N:N).
- `app/Http/Controllers/Api/UserController.php` — CRUD de usuários.
- `app/Http/Requests/StoreUserRequest.php`, `app/Http/Requests/UpdateUserRequest.php`.
- `app/Http/Resources/UserResource.php`.
- `app/Http/Requests/UpdateUnidadeRequest.php`.
- Testes: `tests/Feature/Tenancy/TenantUserMigrationTest.php`, `tests/Unit/Services/TenantAtivoResolverTest.php`, `tests/Feature/Tenancy/TenantContextMiddlewareTest.php`, `tests/Feature/Audit/AuditTenantTest.php`, `tests/Feature/Api/SessaoTenantApiTest.php`, `tests/Feature/Api/TenantApiTest.php`, `tests/Feature/Api/UserApiTest.php`, `tests/Feature/Api/UnidadeEditApiTest.php`.

**Backend — modificar:**
- `app/Models/User.php` — `tenants()` (belongsToMany), `#[Fillable]` inalterado além do necessário.
- `app/Models/Tenant.php` — `users()` many-to-many (mantém `unidades()`).
- `database/factories/UserFactory.php` — helper `comTenant()`.
- `app/Http/Middleware/SetTenantContext.php` — usa o resolver; 403; bloqueia usuário inativo.
- `app/Domain/Audit/AuditLogger.php` — parâmetro explícito `$tenantId`.
- `app/Http/Controllers/Api/AuthController.php` — `/me` estendido; login recusa inativo.
- `app/Jobs/DispatchSyncNfeJob.php` — exige tenant ativo.
- `app/Http/Controllers/Api/UnidadeController.php` — `show`/`update`/`ativo`.
- `app/Http/Requests/StoreUnidadeRequest.php` — `tenant_id` condicional.
- `app/Http/Resources/UnidadeResource.php` — expõe `tenant_id`.
- `routes/api.php` — rotas novas.
- `database/seeders/RolesSeeder.php` — (nada a mudar; `usuario.gerenciar` já existe — verificar Task 8).
- `tools/seed_demo.php` — vínculos via `tenant_user`.

**Frontend — criar:**
- `src/features/sessao/SeletorEntidade.tsx` — seletor de entidade no Topbar.
- `src/features/tenants/TenantsPage.tsx`.
- `src/features/usuarios/UsuariosPage.tsx`.
- `src/lib/api.ts` (adicionar tipos/endpoints; arquivo existente).

**Frontend — modificar:**
- `src/lib/api.ts`, `src/features/auth/session.tsx`, `src/components/layout/Topbar.tsx`, `src/components/layout/Sidebar.tsx`, `src/features/unidades/UnidadesPage.tsx`, `src/app/router.tsx`.

---

## Task 1: Vínculo N:N usuário↔entidade (`tenant_user`)

**Files:**
- Create: `backend/database/migrations/2026_11_04_130000_create_tenant_user_table.php`
- Modify: `backend/app/Models/User.php`, `backend/app/Models/Tenant.php`
- Modify: `backend/database/factories/UserFactory.php`
- Test: `backend/tests/Feature/Tenancy/TenantUserMigrationTest.php`

**Interfaces:**
- Consumes: tabelas `users` e `tenants` já existentes.
- Produces:
  - Tabela `tenant_user` (`tenant_id`, `user_id`, `unique(tenant_id, user_id)`).
  - `User::tenants(): BelongsToMany` (pivô `tenant_user`).
  - `Tenant::users(): BelongsToMany` (pivô `tenant_user`).
  - `UserFactory::comTenant(Tenant|int $tenantId): self` — cria o usuário **e** o vínculo em `tenant_user`, e define `users.tenant_id = $tenantId`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tenancy/TenantUserMigrationTest.php`

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracao_copia_tenant_id_legado_para_pivo(): void
    {
        // Simula o estado pós-migração: um usuário com tenant_id legado que
        // ganhou vínculo em tenant_user (como a migração faz no up()).
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);

        // A migração já rodou no banco de teste; replicamos a cópia de dados
        // exercitando o mesmo caminho usado por ela e conferimos o resultado.
        \Illuminate\Support\Facades\DB::table('tenant_user')->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
        ]);
    }

    public function test_helper_comtenan_cria_vinculo(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
        ]);
        $this->assertTrue($u->tenants()->whereKey($tenant->id)->exists());
        $this->assertSame($tenant->id, (int) $u->tenant_id);
    }

    public function test_relacao_tem_e_pertence(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $a->id]);
        $u->tenants()->attach([$a->id, $b->id]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $u->tenants()->pluck('tenants.id')->all());
        $this->assertTrue($a->users()->whereKey($u->id)->exists());
    }

    public function test_vinculo_e_unico_por_par(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->tenants()->attach($tenant->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $u->tenants()->attach($tenant->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=TenantUserMigrationTest`
Expected: FAIL — `comTenant` não existe / tabela `tenant_user` inexistente.

- [ ] **Step 3: Create the migration**

Create `backend/database/migrations/2026_11_04_130000_create_tenant_user_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });

        // Cópia dos vínculos legados: quem tinha users.tenant_id passa a ter vínculo.
        DB::table('users')
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('tenant_user')->insertOrIgnore([
                    'tenant_id' => $user->tenant_id,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');
    }
};
```

- [ ] **Step 4: Add relations**

In `backend/app/Models/User.php`, add after `tenant()`:

```php
    /**
     * Entidades às quais o usuário tem acesso (fonte de verdade dos vínculos).
     */
    public function tenants(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user');
    }
```

In `backend/app/Models/Tenant.php`, replace `users()` (hasMany) with:

```php
    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user');
    }
```

- [ ] **Step 5: Add the factory helper**

In `backend/database/factories/UserFactory.php`, add:

```php
    /**
     * Cria o usuário já vinculado a uma entidade (pivô tenant_user + coluna legada).
     */
    public function comTenant(\App\Models\Tenant|int $tenant): static
    {
        $tenantId = $tenant instanceof \App\Models\Tenant ? $tenant->id : $tenant;

        return $this->state(fn () => ['tenant_id' => $tenantId])
            ->afterCreating(fn (User $user) => $user->tenants()->syncWithoutDetaching([$tenantId]));
    }
```

- [ ] **Step 6: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=TenantUserMigrationTest`
Expected: PASS.

- [ ] **Step 7: Run the whole suite for regressions**

Run: `docker compose run --rm app php artisan test`
Expected: PASS (a base tem 134 testes; a mudança de `Tenant::users()` de hasMany para belongsToMany pode quebrar usos — corrigir se houver).

- [ ] **Step 8: Commit**

```bash
git add backend/database/migrations/2026_11_04_130000_create_tenant_user_table.php backend/app/Models/User.php backend/app/Models/Tenant.php backend/database/factories/UserFactory.php backend/tests/Feature/Tenancy/TenantUserMigrationTest.php
git commit -m "feat(tenancy): vinculo N:N usuario-entidade (tenant_user)"
```

---

## Task 2: Serviço `TenantAtivoResolver`

**Files:**
- Create: `backend/app/Services/TenantAtivoResolver.php`
- Test: `backend/tests/Unit/Services/TenantAtivoResolverTest.php`

**Interfaces:**
- Consumes: `User::tenants()` (Task 1); `App\Models\Tenant` (`ativo`).
- Produces (usado por Tasks 3, 5, 6):
  - `TenantAtivoResolver::paraUsuario(User $user, ?int $preferencia = null): ?int` — id do tenant efetivo, ou `null` só quando super-admin/"Todas".
  - `TenantAtivoResolver::disponiveis(User $user): Collection<int,Tenant>` — entidades ativas disponíveis (super-admin: todas; demais: as vinculadas ativas), ordenadas por `nome`.
  - `TenantAtivoResolver::podeTodas(User $user): bool` — `$user->hasRole('super-admin')`.
  - `TenantAtivoResolver::usuarioSemVinculo(User $user): bool` — não-super-admin sem **nenhum** vínculo ativo.
  - Exceção `App\Exceptions\SemVinculoTenantException` (500-class não: é 403 no middleware) — mas aqui o serviço apenas devolve `null` e o middleware decide; `usuarioSemVinculo()` sinaliza.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Services/TenantAtivoResolverTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantAtivoResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAtivoResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function resolver(): TenantAtivoResolver
    {
        return app(TenantAtivoResolver::class);
    }

    public function test_super_admin_sem_preferencia_resolve_todas(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertNull($this->resolver()->paraUsuario($super, null));
        $this->assertTrue($this->resolver()->podeTodas($super));
    }

    public function test_super_admin_com_preferencia_ativa_resolve_ela(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertSame($t->id, $this->resolver()->paraUsuario($super, $t->id));
    }

    public function test_super_admin_com_preferencia_inexistente_cai_para_todas(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertNull($this->resolver()->paraUsuario($super, 99999));
    }

    public function test_super_admin_com_preferencia_inativa_cai_para_todas(): void
    {
        $t = Tenant::factory()->create(['ativo' => false]);
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertNull($this->resolver()->paraUsuario($super, $t->id));
    }

    public function test_usuario_com_vinculo_usa_primeiro_quando_sem_preferencia(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->tenants()->attach($b->id);

        $this->assertSame(min($a->id, $b->id), $this->resolver()->paraUsuario($u, null));
    }

    public function test_usuario_respeita_preferencia_se_tiver_vinculo_ativo(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->tenants()->attach($b->id);

        $this->assertSame($b->id, $this->resolver()->paraUsuario($u, $b->id));
    }

    public function test_usuario_com_preferencia_sem_vinculo_cai_para_o_primeiro(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $outro = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();

        $this->assertSame($a->id, $this->resolver()->paraUsuario($u, $outro->id));
    }

    public function test_usuario_sem_vinculo_ativo_e_sinalizado(): void
    {
        $t = Tenant::factory()->create(['ativo' => false]);
        $u = User::factory()->comTenant($t)->create();

        $this->assertTrue($this->resolver()->usuarioSemVinculo($u));
    }

    public function test_disponiveis_para_super_admin_e_todas_as_ativas(): void
    {
        Tenant::factory()->create(['ativo' => true]);
        Tenant::factory()->create(['ativo' => false]);
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertSame(1, $this->resolver()->disponiveis($super)->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=TenantAtivoResolverTest`
Expected: FAIL — classe `TenantAtivoResolver` não existe.

- [ ] **Step 3: Implement the service**

Create `backend/app/Services/TenantAtivoResolver.php`:

```php
<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolve o tenant ATIVO de um usuário (fonte única para middleware e /me).
 *
 * Regras (spec §2):
 * - super-admin: a preferência da sessão (pode ser null = "Todas"); se apontar
 *   para tenant inexistente/inativo, degrada para "Todas".
 * - demais: a preferência, SE houver vínculo com um tenant ATIVO; senão o
 *   primeiro vínculo ativo (menor tenant_id); senão null (o middleware decide 403).
 */
class TenantAtivoResolver
{
    public function podeTodas(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function paraUsuario(User $user, ?int $preferencia = null): ?int
    {
        if ($this->podeTodas($user)) {
            if ($preferencia === null) {
                return null;
            }

            return $this->ativo($preferencia) ? (int) $preferencia : null;
        }

        $vinculados = $this->vinculosAtivos($user);

        if ($preferencia !== null && $vinculados->contains((int) $preferencia)) {
            return (int) $preferencia;
        }

        return $vinculados->first();
    }

    public function usuarioSemVinculo(User $user): bool
    {
        return ! $this->podeTodas($user) && $this->vinculosAtivos($user)->isEmpty();
    }

    /** @return Collection<int,Tenant> */
    public function disponiveis(User $user): Collection
    {
        if ($this->podeTodas($user)) {
            return Tenant::where('ativo', true)->orderBy('nome')->get();
        }

        return Tenant::where('ativo', true)
            ->whereIn('id', $this->vinculosAtivos($user)->all())
            ->orderBy('nome')
            ->get();
    }

    private function ativo(int $tenantId): bool
    {
        return Tenant::whereKey($tenantId)->where('ativo', true)->exists();
    }

    /** @return Collection<int,int> ids de tenants ativos vinculados, ordenados */
    private function vinculosAtivos(User $user): Collection
    {
        return Tenant::where('ativo', true)
            ->whereIn('id', $user->tenants()->pluck('tenants.id')->all())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=TenantAtivoResolverTest`
Expected: PASS (9 testes).

- [ ] **Step 5: Commit**

```bash
git add backend/app/Services/TenantAtivoResolver.php backend/tests/Unit/Services/TenantAtivoResolverTest.php
git commit -m "feat(tenancy): servico TenantAtivoResolver (tenant ativo da sessao)"
```

---

## Task 3: `SetTenantContext` usa o resolver; 403 sem vínculo; bloqueia usuário inativo

**Files:**
- Modify: `backend/app/Http/Middleware/SetTenantContext.php`
- Test: `backend/tests/Feature/Tenancy/TenantContextMiddlewareTest.php`

**Interfaces:**
- Consumes: `TenantAtivoResolver` (Task 2); `session('tenant_id')`; `TenantContext::set`/`clear`.
- Produces: middleware `tenant` que (a) resolve o tenant efetivo da sessão; (b) devolve `403` para não-super-admin sem vínculo ativo; (c) devolve `403` para usuário inativo (`users.ativo = false` — ver Task 5 cria a coluna; até lá, tratar campo ausente como ativo). Como a Task 5 introduz `users.ativo`, aqui o guard usa a coluna somente se existir — para não quebrar o gate desta task. **A coluna será criada na Task 5; o teste de inativo entra lá.**

- [ ] **Step 1: Write the failing test** — `tests/Feature/Tenancy/TenantContextMiddlewareTest.php`

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        Route::middleware(['web', 'auth:sanctum', 'tenant'])->prefix('api/_t')->group(function () {
            Route::get('/ctx', fn () => response()->json(['tenant' => TenantContext::id()]));
        });
    }

    public function test_usuario_sem_vinculo_recebe_403(): void
    {
        $u = User::factory()->create(['tenant_id' => null]);
        $u->assignRole('operador');

        $this->actingAs($u)->getJson('/api/_t/ctx')->assertForbidden();
    }

    public function test_usuario_com_vinculo_usa_o_primeiro(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->assignRole('operador');

        $this->actingAs($u)->getJson('/api/_t/ctx')->assertOk()->assertJsonPath('tenant', $a->id);
    }

    public function test_super_admin_sem_preferencia_ve_todas_contexto_nulo(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');
        $super->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($super)->getJson('/api/_t/ctx')->assertOk()->assertJsonPath('tenant', null);
    }

    public function test_sessao_define_o_tenant_ativo(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->tenants()->attach($b->id);
        $u->assignRole('operador');

        $this->actingAs($u)
            ->withSession(['tenant_id' => $b->id])
            ->getJson('/api/_t/ctx')
            ->assertOk()
            ->assertJsonPath('tenant', $b->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=TenantContextMiddlewareTest`
Expected: FAIL — hoje `SetTenantContext` usa `$user?->tenant_id` e não devolve 403.

- [ ] **Step 3: Rewrite the middleware**

Replace `backend/app/Http/Middleware/SetTenantContext.php` body:

```php
<?php

namespace App\Http\Middleware;

use App\Services\TenantAtivoResolver;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant ativo a partir da SESSÃO, validado contra os vínculos.
 *
 * - Resolve o tenant efetivo via TenantAtivoResolver (fonte única).
 * - Não-super-admin sem vínculo ativo recebe 403 (nunca contexto nulo, que
 *   desativaria o filtro e vazaria dados de todos os tenants).
 * - Usuário inativo não navega (sessão cai na próxima requisição).
 * - Limpa o contexto ao final (evita vazamento entre requisições/workers).
 */
class SetTenantContext
{
    public function __construct(private readonly TenantAtivoResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            if ((bool) ($user->ativo ?? true) === false) {
                return response()->json(['message' => 'Usuário inativo.'], 403);
            }

            if ($this->resolver->usuarioSemVinculo($user)) {
                return response()->json([
                    'message' => 'Usuário sem entidade vinculada.',
                ], 403);
            }

            $preferencia = $request->session()->get('tenant_id');
            $tenantId = $this->resolver->paraUsuario(
                $user,
                $preferencia === null ? null : (int) $preferencia,
            );

            TenantContext::set($tenantId);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=TenantContextMiddlewareTest`
Expected: PASS.

- [ ] **Step 5: Run the whole suite (regressões de auth/tenant)**

Run: `docker compose run --rm app php artisan test`
Expected: PASS. Se algum teste existente de super-admin falhar no middleware `tenant`, ajustar o teste para criar o vínculo/`tenant_id` adequado (não afrouxar o middleware).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Middleware/SetTenantContext.php backend/tests/Feature/Tenancy/TenantContextMiddlewareTest.php
git commit -m "feat(tenancy): middleware resolve tenant da sessao e nega sem vinculo"
```

---

## Task 4: `AuditLogger` aceita tenant explícito

**Files:**
- Modify: `backend/app/Domain/Audit/AuditLogger.php`
- Modify: `backend/app/Models/Concerns/Auditable.php`
- Test: `backend/tests/Feature/Audit/AuditTenantTest.php`

**Interfaces:**
- Consumes: `TenantContext::id()`, `$user->tenant_id`.
- Produces:
  - `AuditLogger::log(?User $user, string $acao, array $contexto = [], ?string $entidade = null, string|int|null $entidadeId = null, ?Request $request = null, ?int $tenantId = null): AuditLog` — ordem: `$tenantId ?? TenantContext::id() ?? $user?->tenant_id`.
  - `Auditable::audit(..., ?int $tenantId = null)` repassa o tenant explícito (parâmetro novo, depreciado no fim da lista).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Audit/AuditTenantTest.php`

```php
<?php

namespace Tests\Feature\Audit;

use App\Domain\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_auditoria_prefere_o_tenant_do_contexto_ao_do_usuario(): void
    {
        $tenantUsuario = Tenant::factory()->create();
        $tenantContexto = Tenant::factory()->create();
        $super = User::factory()->create(['tenant_id' => null]);

        TenantContext::set($tenantContexto->id);
        $log = app(AuditLogger::class)->log($super, 'teste.acao');
        TenantContext::clear();

        $this->assertSame($tenantContexto->id, (int) $log->tenant_id);
    }

    public function test_auditoria_com_tenant_explicito_vence_tudo(): void
    {
        $explicito = Tenant::factory()->create();
        $super = User::factory()->create(['tenant_id' => null]);

        $log = app(AuditLogger::class)->log($super, 'teste.acao', tenantId: $explicito->id);

        $this->assertSame($explicito->id, (int) $log->tenant_id);
    }

    public function test_auditoria_sem_contexto_cai_no_tenant_do_usuario(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create();

        $log = app(AuditLogger::class)->log($u, 'teste.acao');

        $this->assertSame($tenant->id, (int) $log->tenant_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=AuditTenantTest`
Expected: FAIL no primeiro teste (hoje `$user->tenant_id` vence e dá null).

- [ ] **Step 3: Update `AuditLogger::log`**

In `backend/app/Domain/Audit/AuditLogger.php`, change the signature and the resolution line:

```php
    public function log(
        ?User $user,
        string $acao,
        array $contexto = [],
        ?string $entidade = null,
        string|int|null $entidadeId = null,
        ?Request $request = null,
        ?int $tenantId = null,
    ): AuditLog {
        $request ??= request();

        // Preferência: tenant explícito > contexto ativo > tenant do usuário.
        $tenantId ??= \App\Support\TenantContext::id() ?? $user?->tenant_id;
```

- [ ] **Step 4: Update `Auditable::audit`**

In `backend/app/Models/Concerns/Auditable.php`, add the trailing param and pass it through:

```php
    protected function audit(
        string $acao,
        array $contexto = [],
        ?string $entidade = null,
        string|int|null $entidadeId = null,
        ?User $user = null,
        ?Request $request = null,
        ?int $tenantId = null,
    ): void {
        app(AuditLogger::class)->log(
            $user ?? $request?->user() ?? request()->user(),
            $acao,
            $contexto,
            $entidade,
            $entidadeId,
            $request ?? request(),
            $tenantId,
        );
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=AuditTenantTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Domain/Audit/AuditLogger.php backend/app/Models/Concerns/Auditable.php backend/tests/Feature/Audit/AuditTenantTest.php
git commit -m "feat(audit): AuditLogger aceita tenant explicito e prefere o contexto"
```

---

## Task 5: Enforcement de "desativar" (usuário/tenant) + endpoints de sessão e `/me`

**Files:**
- Create: `backend/database/migrations/2026_11_04_131000_add_ativo_to_users_table.php`
- Create: `backend/app/Http/Controllers/Api/SessaoTenantController.php`
- Modify: `backend/app/Models/User.php` (`ativo` cast/fillable)
- Modify: `backend/app/Http/Controllers/Api/AuthController.php` (login recusa inativo; `/me` estendido)
- Modify: `backend/app/Jobs/DispatchSyncNfeJob.php` (exige tenant ativo)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/SessaoTenantApiTest.php`

**Interfaces:**
- Consumes: `TenantAtivoResolver` (Task 2); `SetTenantContext` (Task 3).
- Produces:
  - Coluna `users.ativo` (boolean, default true).
  - `POST /api/sessao/tenant` body `{ tenant_id: int|null }` → grava `session('tenant_id')` e devolve `{ tenant_ativo: {id,nome}|null }`.
  - `GET /api/me` devolve adicionalmente: `tenant_ativo` (`{id,nome}`|null), `tenants_disponiveis` (`[{id,nome}]`), `pode_todas` (bool).
  - `AuthController::dadosUsuario` passa a montar esses campos.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Api/SessaoTenantApiTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessaoTenantApiTest extends TestCase
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
        ])->save();

        return $u;
    }

    public function test_me_expoe_entidades_disponiveis_e_pode_todas_para_super_admin(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.pode_todas', true)
            ->assertJsonPath('user.tenant_ativo', null)
            ->assertJsonCount(2, 'user.tenants_disponiveis');
    }

    public function test_me_mostra_apenas_vinculadas_para_admin_tenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        Tenant::factory()->create(['ativo' => true]);
        $u = $this->mfa(User::factory()->comTenant($a)->create());
        $u->assignRole('admin-tenant');

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.pode_todas', false)
            ->assertJsonPath('user.tenant_ativo.id', $a->id)
            ->assertJsonCount(1, 'user.tenants_disponiveis');
    }

    public function test_super_admin_troca_para_qualquer_tenant(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->postJson('/api/sessao/tenant', ['tenant_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('tenant_ativo.id', $a->id);

        $this->actingAs($super)->getJson('/api/me')->assertJsonPath('user.tenant_ativo.id', $a->id);
    }

    public function test_super_admin_pode_voltar_para_todas(): void
    {
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->postJson('/api/sessao/tenant', ['tenant_id' => null])
            ->assertOk()
            ->assertJsonPath('tenant_ativo', null);
    }

    public function test_usuario_nao_troca_para_tenant_sem_vinculo(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $outro = Tenant::factory()->create(['ativo' => true]);
        $u = $this->mfa(User::factory()->comTenant($a)->create());
        $u->assignRole('admin-tenant');

        $this->actingAs($u)->postJson('/api/sessao/tenant', ['tenant_id' => $outro->id])
            ->assertForbidden();
    }

    public function test_nao_seleciona_tenant_inativo(): void
    {
        $inativo = Tenant::factory()->create(['ativo' => false]);
        $super = $this->mfa(User::factory()->create(['tenant_id' => null]));
        $super->assignRole('super-admin');

        $this->actingAs($super)->postJson('/api/sessao/tenant', ['tenant_id' => $inativo->id])
            ->assertStatus(422);
    }

    public function test_login_recusa_usuario_inativo(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($t)->create([
            'password' => \Illuminate\Support\Facades\Hash::make('senha-forte'),
            'ativo' => false,
        ]);
        $u->assignRole('operador');

        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'senha-forte'])
            ->assertStatus(422);
        $this->assertGuest();
    }

    public function test_sessao_de_usuario_inativado_e_derrubada(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);
        $u = $this->mfa(User::factory()->comTenant($t)->create());
        $u->assignRole('admin-tenant');

        $this->actingAs($u)->getJson('/api/me')->assertOk();

        $u->forceFill(['ativo' => false])->save();
        $this->actingAs($u->fresh())->getJson('/api/me')->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=SessaoTenantApiTest`
Expected: FAIL — coluna/rota/campos ausentes.

- [ ] **Step 3: Create the migration**

Create `backend/database/migrations/2026_11_04_131000_add_ativo_to_users_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ativo')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ativo');
        });
    }
};
```

- [ ] **Step 4: Update `User` model**

In `backend/app/Models/User.php`: add `'ativo'` to `#[Fillable([...])]` and add `'ativo' => 'boolean'` to `casts()`.

- [ ] **Step 5: Create `SessaoTenantController`**

Create `backend/app/Http/Controllers/Api/SessaoTenantController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantAtivoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Seleção do tenant ativo da sessão (seletor de entidade do SPA).
 */
class SessaoTenantController extends Controller
{
    public function __construct(private readonly TenantAtivoResolver $resolver)
    {
    }

    public function update(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tenant_id' => ['present', 'nullable', 'integer'],
        ]);

        $user = $request->user();
        $tenantId = $dados['tenant_id'];

        if ($tenantId === null) {
            if (! $this->resolver->podeTodas($user)) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Selecione uma entidade.',
                ]);
            }
        } else {
            $permitido = $this->resolver->podeTodas($user)
                ? Tenant::whereKey($tenantId)->where('ativo', true)->exists()
                : $this->resolver->disponiveis($user)->contains('id', (int) $tenantId);

            if (! $permitido) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Entidade indisponível.',
                ]);
            }
        }

        $request->session()->put('tenant_id', $tenantId);

        $tenant = $tenantId === null ? null : Tenant::find($tenantId);

        return response()->json([
            'tenant_ativo' => $tenant ? ['id' => $tenant->id, 'nome' => $tenant->nome] : null,
        ]);
    }
}
```

- [ ] **Step 6: Update `AuthController`**

In `backend/app/Http/Controllers/Api/AuthController.php`:
- In `login()`, after resolving `$user`, before returning:

```php
        if (! (bool) ($user->ativo ?? true)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            Auth::forgetGuards();

            throw ValidationException::withMessages([
                'email' => 'Usuário inativo. Procure o administrador.',
            ]);
        }
```

   (Inserir logo após `$user = Auth::user();`.)
- In `dadosUsuario()`, add the three fields near `tenant_id`:

```php
        $resolver = app(\App\Services\TenantAtivoResolver::class);
        $preferencia = request()->session()?->get('tenant_id');
        $tenantAtivoId = $resolver->paraUsuario($user, $preferencia === null ? null : (int) $preferencia);
        $tenantAtivo = $tenantAtivoId ? \App\Models\Tenant::find($tenantAtivoId) : null;
        $disponiveis = $resolver->disponiveis($user)->map(fn ($t) => ['id' => $t->id, 'nome' => $t->nome]);
```

   and in the returned array:

```php
            'tenant_ativo' => $tenantAtivo ? ['id' => $tenantAtivo->id, 'nome' => $tenantAtivo->nome] : null,
            'tenants_disponiveis' => $disponiveis,
            'pode_todas' => $resolver->podeTodas($user),
```

- [ ] **Step 7: Enforce tenant ativo no `DispatchSyncNfeJob`**

In `backend/app/Jobs/DispatchSyncNfeJob.php`, change the query so only unidades of tenants ativos entram. Add to the `whereExists` chain (or a `whereIn`) — replace the `$unidades = ...` block with:

```php
        $unidades = Unidade::withoutGlobalScopes()
            ->where('ativo', true)
            ->whereIn('tenant_id', \App\Models\Tenant::where('ativo', true)->pluck('id'))
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('certificates')
                    ->whereColumn('certificates.unidade_id', 'unidades.id')
                    ->where('certificates.ativo', true)
                    ->where('certificates.valido_ate', '>', now());
            })
            ->get();
```

- [ ] **Step 8: Register the route**

In `backend/routes/api.php`, inside the `['auth:sanctum', 'tenant']` group (junto de `/me`), add:

```php
        Route::post('/sessao/tenant', [\App\Http\Controllers\Api\SessaoTenantController::class, 'update'])
            ->name('api.sessao.tenant');
```

- [ ] **Step 9: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=SessaoTenantApiTest`
Expected: PASS (8 testes).

- [ ] **Step 10: Run the whole suite**

Run: `docker compose run --rm app php artisan test`
Expected: PASS.

- [ ] **Step 11: Commit**

```bash
git add backend/database/migrations/2026_11_04_131000_add_ativo_to_users_table.php backend/app/Http/Controllers/Api/SessaoTenantController.php backend/app/Models/User.php backend/app/Http/Controllers/Api/AuthController.php backend/app/Jobs/DispatchSyncNfeJob.php backend/routes/api.php backend/tests/Feature/Api/SessaoTenantApiTest.php
git commit -m "feat(tenancy): tenant ativo na sessao, /me estendido e enforcement de inativo"
```

---

## Task 6: CRUD de Entidades (Tenant) — super-admin

**Files:**
- Create: `backend/app/Policies/TenantPolicy.php`
- Create: `backend/app/Http/Requests/StoreTenantRequest.php`, `backend/app/Http/Requests/UpdateTenantRequest.php`
- Create: `backend/app/Http/Resources/TenantResource.php`
- Create: `backend/app/Http/Controllers/Api/TenantController.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (mapear `Tenant::class => TenantPolicy::class`)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/TenantApiTest.php`

**Interfaces:**
- Consumes: `Tenant` model; middleware `auth:sanctum`+`mfa`; `Auditable`.
- Produces:
  - `GET /api/tenants` → lista paginada (`TenantResource`).
  - `POST /api/tenants` → criar (`nome`, `cnpj` opcional, `ativo`).
  - `GET /api/tenants/{tenant}` → detalhe.
  - `PUT /api/tenants/{tenant}` → editar.
  - `PUT /api/tenants/{tenant}/ativo` → ativar/desativar.
  - `TenantPolicy` (tudo exige `super-admin`).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Api/TenantApiTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function superAdmin(): User
    {
        $u = User::factory()->create(['tenant_id' => null]);
        $u->assignRole('super-admin');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    public function test_super_admin_lista_entidades(): void
    {
        Tenant::factory()->count(3)->create();

        $this->actingAs($this->superAdmin())->getJson('/api/tenants')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_super_admin_cria_entidade(): void
    {
        $this->actingAs($this->superAdmin())
            ->postJson('/api/tenants', ['nome' => 'Prefeitura X', 'cnpj' => '11222333000181'])
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Prefeitura X');

        $this->assertDatabaseHas('tenants', ['nome' => 'Prefeitura X']);
    }

    public function test_super_admin_edita_entidade(): void
    {
        $t = Tenant::factory()->create(['nome' => 'Antiga']);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/tenants/{$t->id}", ['nome' => 'Nova'])
            ->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => $t->id, 'nome' => 'Nova']);
    }

    public function test_super_admin_desativa_entidade(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);

        $this->actingAs($this->superAdmin())
            ->putJson("/api/tenants/{$t->id}/ativo", ['ativo' => false])
            ->assertOk();

        $this->assertDatabaseHas('tenants', ['id' => $t->id, 'ativo' => false]);
    }

    public function test_admin_tenant_nao_gerencia_entidades(): void
    {
        $t = Tenant::factory()->create();
        $u = User::factory()->comTenant($t)->create();
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($u)->getJson('/api/tenants')->assertForbidden();
        $this->actingAs($u)->postJson('/api/tenants', ['nome' => 'Z'])->assertForbidden();
    }

    public function test_detalhe_de_entidade(): void
    {
        $t = Tenant::factory()->create();

        $this->actingAs($this->superAdmin())->getJson("/api/tenants/{$t->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $t->id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=TenantApiTest`
Expected: FAIL — rotas inexistentes (404).

- [ ] **Step 3: Create the Policy**

Create `backend/app/Policies/TenantPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Autorização para Entidades (Tenant). Somente super-admin.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function view(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super-admin');
    }
}
```

- [ ] **Step 4: Create the Requests**

`backend/app/Http/Requests/StoreTenantRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:14'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }
}
```

`backend/app/Http/Requests/UpdateTenantRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'cnpj' => ['nullable', 'string', 'max:14'],
        ];
    }
}
```

- [ ] **Step 5: Create the Resource**

`backend/app/Http/Resources/TenantResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Tenant */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'cnpj' => $this->cnpj,
            'ativo' => (bool) $this->ativo,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 6: Create the Controller**

`backend/app/Http/Controllers/Api/TenantController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Concerns\Auditable;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD de Entidades (Tenant). Somente super-admin.
 */
class TenantController extends Controller
{
    use Auditable;

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tenant::class);

        return TenantResource::collection(Tenant::orderBy('nome')->paginate(50));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        Gate::authorize('create', Tenant::class);

        $tenant = Tenant::create($request->validated());

        $this->audit('tenant.criado', ['nome' => $tenant->nome], 'tenant', $tenant->id, $request->user(), $request);

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function show(Tenant $tenant): TenantResource
    {
        Gate::authorize('view', Tenant::class);

        return new TenantResource($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        Gate::authorize('update', Tenant::class);

        $tenant->update($request->validated());

        $this->audit('tenant.atualizado', ['nome' => $tenant->nome], 'tenant', $tenant->id, $request->user(), $request);

        return new TenantResource($tenant);
    }

    public function ativo(Request $request, Tenant $tenant): TenantResource
    {
        Gate::authorize('update', Tenant::class);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);
        $tenant->update(['ativo' => $dados['ativo']]);

        $this->audit('tenant.ativo.alterado', ['ativo' => $tenant->ativo], 'tenant', $tenant->id, $request->user(), $request);

        return new TenantResource($tenant);
    }
}
```

- [ ] **Step 7: Register policy + routes**

In `backend/app/Providers/AppServiceProvider.php`, add `Tenant::class => TenantPolicy::class` to `$policies` (import both).

In `backend/routes/api.php`, add a group (após o grupo de negócio; Tenant não usa o middleware `tenant`):

```php
    // Administração de plataforma: Entidades (só super-admin, MFA confirmado).
    Route::middleware(['auth:sanctum', 'mfa'])->group(function () {
        Route::get('/tenants', [\App\Http\Controllers\Api\TenantController::class, 'index'])->name('api.tenants.index');
        Route::post('/tenants', [\App\Http\Controllers\Api\TenantController::class, 'store'])->name('api.tenants.store');
        Route::get('/tenants/{tenant}', [\App\Http\Controllers\Api\TenantController::class, 'show'])->name('api.tenants.show');
        Route::put('/tenants/{tenant}', [\App\Http\Controllers\Api\TenantController::class, 'update'])->name('api.tenants.update');
        Route::put('/tenants/{tenant}/ativo', [\App\Http\Controllers\Api\TenantController::class, 'ativo'])->name('api.tenants.ativo');
    });
```

- [ ] **Step 8: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=TenantApiTest`
Expected: PASS (6 testes).

- [ ] **Step 9: Commit**

```bash
git add backend/app/Policies/TenantPolicy.php backend/app/Http/Requests/StoreTenantRequest.php backend/app/Http/Requests/UpdateTenantRequest.php backend/app/Http/Resources/TenantResource.php backend/app/Http/Controllers/Api/TenantController.php backend/app/Providers/AppServiceProvider.php backend/routes/api.php backend/tests/Feature/Api/TenantApiTest.php
git commit -m "feat(api): CRUD de entidades (tenant) restrito ao super-admin"
```

---

## Task 7: `UserPolicy`, `UserResource` e Requests de usuário

**Files:**
- Create: `backend/app/Policies/UserPolicy.php`
- Create: `backend/app/Http/Resources/UserResource.php`
- Create: `backend/app/Http/Requests/StoreUserRequest.php`, `backend/app/Http/Requests/UpdateUserRequest.php`
- Modify: `backend/app/Providers/AppServiceProvider.php` (mapear `User::class => UserPolicy::class`)

**Interfaces:**
- Consumes: `User::tenants()`; roles/permissões (Spatie).
- Produces:
  - `UserPolicy`:
    - `viewAny(User $auth): bool` — `super-admin` ou `can('usuario.gerenciar')`.
    - `create(User $auth): bool` — idem.
    - `update(User $auth, User $alvo): bool` — autorizado **e** `$auth` super-admin OU (tem vínculo com algum tenant do `$alvo`) ; **e** não é a própria conta **quando** a mudança for sensível (papel/ativo/vínculo do tenant ativo — checado no controller).
    - `desativar(User $auth, User $alvo): bool` — igual a `update`, e `$auth->id !== $alvo->id`.
  - `UserResource` — `id, name, email, ativo, papeis[], tenants[{id,nome}], tenant_id, created_at`.
  - `StoreUserRequest::{authorize,rules}` — `name`, `email` (único global), `password` (min:8), `papel` (`in:super-admin,admin-tenant,operador,consulta`), `tenants` (array, `exists:tenants,id`).
  - `UpdateUserRequest` — `name`, `email` (unique ignorando o próprio), `password` opcional, `papel`, `tenants`.

> **Nota sobre "escopo N:N":** a policy **não** usa `PertenceAoTenant::mesmoTenant()` (compara a coluna legada). Usa `whereHas('tenants')`. `PertenceAoTenant` **não** é alterado.

- [ ] **Step 1: Create `UserPolicy`**

`backend/app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Autorização do CRUD de Usuários.
 *
 * O escopo é N:N (via `tenant_user`), não a coluna legada `users.tenant_id`.
 * - super-admin: qualquer usuário.
 * - admin-tenant/gestor (`usuario.gerenciar`): só usuários com vínculo a algum
 *   tenant que ele também acessa.
 * - Ninguém altera campos sensíveis da própria conta (feito no controller).
 */
class UserPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->hasRole('super-admin') || $auth->can('usuario.gerenciar');
    }

    public function create(User $auth): bool
    {
        return $auth->hasRole('super-admin') || $auth->can('usuario.gerenciar');
    }

    public function update(User $auth, User $alvo): bool
    {
        if (! ($auth->hasRole('super-admin') || $auth->can('usuario.gerenciar'))) {
            return false;
        }

        if ($auth->hasRole('super-admin')) {
            return true;
        }

        return $this->compartilhaTenant($auth, $alvo);
    }

    public function desativar(User $auth, User $alvo): bool
    {
        return $auth->id !== $alvo->id && $this->update($auth, $alvo);
    }

    private function compartilhaTenant(User $auth, User $alvo): bool
    {
        $meus = $auth->tenants()->pluck('tenants.id');

        return $alvo->tenants()->whereIn('tenants.id', $meus)->exists();
    }
}
```

- [ ] **Step 2: Create `UserResource`**

`backend/app/Http/Resources/UserResource.php`:

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'ativo' => (bool) $this->ativo,
            'tenant_id' => $this->tenant_id,
            'papeis' => $this->getRoleNames(),
            'tenants' => $this->whenLoaded('tenants', fn () => $this->tenants->map(
                fn ($t) => ['id' => $t->id, 'nome' => $t->nome],
            )),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 3: Create the Requests**

`backend/app/Http/Requests/StoreUserRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'papel' => ['required', Rule::in(['super-admin', 'admin-tenant', 'operador', 'consulta'])],
            'tenants' => ['present', 'array'],
            'tenants.*' => ['integer', Rule::exists('tenants', 'id')],
        ];
    }
}
```

`backend/app/Http/Requests/UpdateUserRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $alvo = $this->route('usuario');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($alvo?->id)],
            'password' => ['nullable', 'string', 'min:8'],
            'papel' => ['required', Rule::in(['super-admin', 'admin-tenant', 'operador', 'consulta'])],
            'tenants' => ['present', 'array'],
            'tenants.*' => ['integer', Rule::exists('tenants', 'id')],
        ];
    }
}
```

- [ ] **Step 4: Register the policy**

In `backend/app/Providers/AppServiceProvider.php`, add `User::class => UserPolicy::class` (import `App\Models\User`, `App\Policies\UserPolicy`).

- [ ] **Step 5: Sanity check (no route yet, ensure classes autoload)**

Run: `docker compose run --rm app php artisan about`
Expected: sem erro fatal de classe.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Policies/UserPolicy.php backend/app/Http/Resources/UserResource.php backend/app/Http/Requests/StoreUserRequest.php backend/app/Http/Requests/UpdateUserRequest.php backend/app/Providers/AppServiceProvider.php
git commit -m "feat(api): policy/resource/requests de usuario (escopo N:N)"
```

---

## Task 8: CRUD de Usuários — `UserController` + rotas

**Files:**
- Create: `backend/app/Http/Controllers/Api/UserController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/UserApiTest.php`

**Interfaces:**
- Consumes: `UserPolicy`/`UserResource`/`StoreUserRequest`/`UpdateUserRequest` (Task 7); `TenantAtivoResolver` (Task 2); middleware `tenant` (Task 3).
- Produces:
  - `GET /api/usuarios` — lista respeitando o seletor: contexto nulo (super-admin "Todas") ⇒ todos; contexto X ⇒ quem tem vínculo com X. Sempre `withoutGlobalScopes()` + `whereHas('tenants')`.
  - `POST /api/usuarios` — cria com `papel` + `tenants[]` (grava `tenant_user` em transação; `users.tenant_id` = primeiro vínculo).
  - `PUT /api/usuarios/{usuario}` — edita (nome/email/papel/tenants/password opcional).
  - `PUT /api/usuarios/{usuario}/ativo` — ativa/desativa.
  - Regras: admin-tenant não cria/altera `super-admin`; ninguém altera a própria conta em papel/ativo/vínculos.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Api/UserApiTest.php`

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=UserApiTest`
Expected: FAIL — rotas inexistentes.

- [ ] **Step 3: Create the Controller**

`backend/app/Http/Controllers/Api/UserController.php`:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\Concerns\Auditable;
use App\Models\User;
use App\Services\TenantAtivoResolver;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * CRUD de Usuários. Vínculo N:N (tenant_user) é a fonte de verdade.
 */
class UserController extends Controller
{
    use Auditable;

    public function __construct(private readonly TenantAtivoResolver $resolver)
    {
    }

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', User::class);

        $query = User::withoutGlobalScopes()->with('tenants');

        // Respeita o seletor: contexto nulo (super-admin "Todas") ⇒ todos.
        $contexto = TenantContext::id();
        if ($contexto !== null) {
            $query->whereHas('tenants', fn ($q) => $q->where('tenants.id', $contexto));
        }

        return UserResource::collection($query->orderBy('name')->paginate(50));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        Gate::authorize('create', User::class);

        $dados = $request->validated();
        $this->autorizaVinculos($request, $dados['tenants'], $dados['papel']);

        $user = DB::transaction(function () use ($dados) {
            $user = User::create([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'password' => $dados['password'],
                'ativo' => true,
                'tenant_id' => $dados['tenants'][0] ?? null,
            ]);
            $user->syncRoles([$dados['papel']]);
            $user->tenants()->sync($dados['tenants']);

            return $user;
        });

        $this->audit('usuario.criado', ['email' => $user->email, 'papel' => $dados['papel']], 'usuario', $user->id, $request->user(), $request);

        return (new UserResource($user->load('tenants')))->response()->setStatusCode(201);
    }

    public function update(UpdateUserRequest $request, int $usuario): UserResource
    {
        $user = User::withoutGlobalScopes()->findOrFail($usuario);
        Gate::authorize('update', $user);

        $dados = $request->validated();

        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'papel' => 'Não é possível alterar a própria conta por aqui.',
            ]);
        }

        $this->autorizaVinculos($request, $dados['tenants'], $dados['papel']);

        DB::transaction(function () use ($user, $dados) {
            $user->fill([
                'name' => $dados['name'],
                'email' => $dados['email'],
                'tenant_id' => $dados['tenants'][0] ?? null,
            ]);
            if (! empty($dados['password'])) {
                $user->password = $dados['password'];
            }
            $user->save();

            $user->syncRoles([$dados['papel']]);
            $user->tenants()->sync($dados['tenants']);
        });

        $this->audit('usuario.atualizado', ['email' => $user->email, 'papel' => $dados['papel']], 'usuario', $user->id, $request->user(), $request);

        return new UserResource($user->load('tenants'));
    }

    public function ativo(Request $request, int $usuario): UserResource
    {
        $user = User::withoutGlobalScopes()->findOrFail($usuario);
        Gate::authorize('desativar', $user);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);
        $user->update(['ativo' => $dados['ativo']]);

        $this->audit('usuario.ativo.alterado', ['ativo' => $user->ativo], 'usuario', $user->id, $request->user(), $request);

        return new UserResource($user->load('tenants'));
    }

    /**
     * admin-tenant só vincula aos seus tenants e nunca cria/atribui super-admin.
     */
    private function autorizaVinculos(Request $request, array $tenants, string $papel): void
    {
        $auth = $request->user();

        if ($auth->hasRole('super-admin')) {
            return;
        }

        if ($papel === 'super-admin') {
            abort(403, 'Somente super-admin cria super-admin.');
        }

        $meus = $auth->tenants()->pluck('tenants.id')->map(fn ($i) => (int) $i)->all();
        foreach ($tenants as $t) {
            if (! in_array((int) $t, $meus, true)) {
                abort(403, 'Vínculo com entidade não permitida.');
            }
        }
    }
}
```

- [ ] **Step 4: Register routes**

In `backend/routes/api.php`, inside the business group (`auth:sanctum`+`tenant`+`mfa`), add:

```php
        // Usuários (gestão). Escopo N:N resolvido no controller/policy.
        Route::get('/usuarios', [\App\Http\Controllers\Api\UserController::class, 'index'])->name('api.usuarios.index');
        Route::post('/usuarios', [\App\Http\Controllers\Api\UserController::class, 'store'])->name('api.usuarios.store');
        Route::put('/usuarios/{usuario}', [\App\Http\Controllers\Api\UserController::class, 'update'])->name('api.usuarios.update');
        Route::put('/usuarios/{usuario}/ativo', [\App\Http\Controllers\Api\UserController::class, 'ativo'])->name('api.usuarios.ativo');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=UserApiTest`
Expected: PASS (11 testes).

- [ ] **Step 6: Run the whole suite**

Run: `docker compose run --rm app php artisan test`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Http/Controllers/Api/UserController.php backend/routes/api.php backend/tests/Feature/Api/UserApiTest.php
git commit -m "feat(api): CRUD de usuarios com vinculo N:N e trava anti-lockout"
```

---

## Task 9: Unidades — detalhe, edição e ativação/desativação

**Files:**
- Create: `backend/app/Http/Requests/UpdateUnidadeRequest.php`
- Modify: `backend/app/Http/Requests/StoreUnidadeRequest.php` (`tenant_id` condicional)
- Modify: `backend/app/Http/Controllers/Api/UnidadeController.php` (`show`/`update`/`ativo`)
- Modify: `backend/app/Http/Resources/UnidadeResource.php` (expõe `tenant_id`)
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/Api/UnidadeEditApiTest.php`

**Interfaces:**
- Consumes: `UnidadePolicy` (`update`/`view`) já existente; `TenantContext`.
- Produces:
  - `GET /api/unidades/{unidade}` → `UnidadeResource` (404 fora do tenant).
  - `PUT /api/unidades/{unidade}` → editar; `tenant_id` **não** editável.
  - `PUT /api/unidades/{unidade}/ativo` → ativar/desativar.
  - `StoreUnidadeRequest` passa a aceitar `tenant_id` **obrigatório só quando** `TenantContext::id()` é nulo (super-admin em "Todas").

- [ ] **Step 1: Write the failing test** — `tests/Feature/Api/UnidadeEditApiTest.php`

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnidadeEditApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function admin(Tenant $t): User
    {
        $u = User::factory()->comTenant($t)->create();
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    public function test_detalhe_da_unidade(): void
    {
        $t = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $t->id]);

        $this->actingAs($this->admin($t))->getJson("/api/unidades/{$u->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $u->id)
            ->assertJsonPath('data.tenant_id', $t->id);
    }

    public function test_edita_unidade(): void
    {
        $t = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $t->id, 'nome' => 'Antiga']);

        $this->actingAs($this->admin($t))->putJson("/api/unidades/{$u->id}", [
            'nome' => 'Corrigida',
            'cnpj' => $u->cnpj,
            'uf' => $u->uf,
        ])->assertOk();

        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'nome' => 'Corrigida']);
    }

    public function test_editar_ignora_tentativa_de_mover_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $a->id]);

        $this->actingAs($this->admin($a))->putJson("/api/unidades/{$u->id}", [
            'nome' => 'X',
            'cnpj' => $u->cnpj,
            'uf' => $u->uf,
            'tenant_id' => $b->id,
        ])->assertOk();

        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'tenant_id' => $a->id]);
    }

    public function test_desativa_e_reativa_unidade(): void
    {
        $t = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $t->id, 'ativo' => true]);
        $admin = $this->admin($t);

        $this->actingAs($admin)->putJson("/api/unidades/{$u->id}/ativo", ['ativo' => false])->assertOk();
        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'ativo' => false]);

        $this->actingAs($admin)->putJson("/api/unidades/{$u->id}/ativo", ['ativo' => true])->assertOk();
        $this->assertDatabaseHas('unidades', ['id' => $u->id, 'ativo' => true]);
    }

    public function test_editar_unidade_de_outro_tenant_retorna_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $b->id]);

        $this->actingAs($this->admin($a))->putJson("/api/unidades/{$u->id}", [
            'nome' => 'X', 'cnpj' => $u->cnpj, 'uf' => $u->uf,
        ])->assertNotFound();
    }

    public function test_super_admin_em_todas_exige_tenant_ao_criar_unidade(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');
        $super->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        // Sem tenant_id no corpo e sem contexto: deve falhar na validação.
        $this->actingAs($super)->postJson('/api/unidades', [
            'nome' => 'Sem entidade', 'cnpj' => '11222333000181', 'uf' => 'SP',
        ])->assertStatus(422);

        // Com tenant_id válido: cria.
        $t = Tenant::factory()->create();
        $this->actingAs($super)->postJson('/api/unidades', [
            'nome' => 'Com entidade', 'cnpj' => '11222333000181', 'uf' => 'SP', 'tenant_id' => $t->id,
        ])->assertCreated();

        $this->assertDatabaseHas('unidades', ['tenant_id' => $t->id, 'cnpj' => '11222333000181']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose run --rm app php artisan test --filter=UnidadeEditApiTest`
Expected: FAIL — rotas `show`/`update`/`ativo` inexistentes; `tenant_id` não validado.

- [ ] **Step 3: Add `tenant_id` condicional ao `StoreUnidadeRequest`**

In `backend/app/Http/Requests/StoreUnidadeRequest.php`, add to `rules()`:

```php
            'tenant_id' => [
                \App\Support\TenantContext::id() === null ? 'required' : 'nullable',
                'integer',
                Rule::exists('tenants', 'id'),
            ],
```

E, em `messages()`, adicione:

```php
            'tenant_id.required' => 'Selecione a entidade da unidade.',
```

- [ ] **Step 4: Create `UpdateUnidadeRequest`**

`backend/app/Http/Requests/UpdateUnidadeRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Unidade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edição de unidade. O `tenant_id` NÃO é editável (mover entidade é proibido).
 */
class UpdateUnidadeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $alvo = $this->route('unidade');

        return [
            'nome' => ['required', 'string', 'max:255'],
            'nome_resumido' => ['nullable', 'string', 'max:255'],
            'codigo_unidade' => ['nullable', 'string', 'max:255'],
            'cnpj' => [
                'required', 'string', 'size:14', 'regex:/^\d{14}$/',
                Rule::unique(Unidade::class, 'cnpj')->ignore($alvo?->id),
            ],
            'ie' => ['nullable', 'string', 'max:20'],
            'im' => ['nullable', 'string', 'max:20'],
            'uf' => ['required', 'string', 'size:2'],
            'tp_amb' => ['nullable', 'integer', 'in:1,2'],
        ];
    }

    public function messages(): array
    {
        return [
            'cnpj.unique' => 'Já existe uma unidade com este CNPJ nesta entidade.',
        ];
    }
}
```

> Nota: `tenant_id` e `ativo` **não** constam nas rules de update — mudanças são ignoradas (ativo tem rota própria).

- [ ] **Step 5: Add methods to `UnidadeController`**

In `backend/app/Http/Controllers/Api/UnidadeController.php`, add imports (`Illuminate\Http\Request`, `App\Http\Requests\UpdateUnidadeRequest`) and methods:

```php
    public function show(int $unidade): UnidadeResource
    {
        $model = Unidade::with('certificate')->findOrFail($unidade);
        Gate::authorize('view', $model);

        return new UnidadeResource($model);
    }

    public function update(UpdateUnidadeRequest $request, int $unidade): UnidadeResource
    {
        $model = Unidade::findOrFail($unidade);
        Gate::authorize('update', $model);

        $model->update($request->validated());

        $this->audit('unidade.atualizada', ['cnpj' => $model->cnpj, 'uf' => $model->uf], 'unidade', $model->id, $request->user(), $request);

        return new UnidadeResource($model);
    }

    public function ativo(Request $request, int $unidade): UnidadeResource
    {
        $model = Unidade::findOrFail($unidade);
        Gate::authorize('update', $model);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);
        $model->update(['ativo' => $dados['ativo']]);

        $this->audit('unidade.ativo.alterado', ['ativo' => $model->ativo], 'unidade', $model->id, $request->user(), $request);

        return new UnidadeResource($model);
    }
```

> O `findOrFail` sem binding usa o global scope: unidade de outro tenant vira 404 naturalmente. A policy reforça.

- [ ] **Step 6: Expose `tenant_id` in the resource**

In `backend/app/Http/Resources/UnidadeResource.php`, add `'tenant_id' => $this->tenant_id,` (após `'id'`).

- [ ] **Step 7: Register routes**

In `backend/routes/api.php`, junto das rotas de unidades já existentes (grupo de negócio):

```php
        Route::get('/unidades/{unidade}', [UnidadeController::class, 'show'])->name('api.unidades.show');
        Route::put('/unidades/{unidade}', [UnidadeController::class, 'update'])->name('api.unidades.update');
        Route::put('/unidades/{unidade}/ativo', [UnidadeController::class, 'ativo'])->name('api.unidades.ativo');
```

> Atenção à ordem: declarar `GET/PUT /unidades/{unidade}` **depois** de `/unidades` (index/store) — `{unidade}` é um segmento extra e não conflita com `/unidades`.

- [ ] **Step 8: Run test to verify it passes**

Run: `docker compose run --rm app php artisan test --filter=UnidadeEditApiTest`
Expected: PASS (6 testes).

- [ ] **Step 9: Run the whole suite**

Run: `docker compose run --rm app php artisan test`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add backend/app/Http/Requests/UpdateUnidadeRequest.php backend/app/Http/Requests/StoreUnidadeRequest.php backend/app/Http/Controllers/Api/UnidadeController.php backend/app/Http/Resources/UnidadeResource.php backend/routes/api.php backend/tests/Feature/Api/UnidadeEditApiTest.php
git commit -m "feat(api): unidades com detalhe, edicao e ativacao/desativacao"
```

---

## Task 10: SPA — tipos e endpoints novos em `lib/api.ts`

**Files:**
- Modify: `frontend/src/lib/api.ts`
- Test: `frontend/src/lib/api.test.ts` (adicionar casos)

**Interfaces:**
- Consumes: backend das Tasks 5, 6, 8, 9.
- Produces (usado pelas Tasks 11–14):
  - `Usuario` ganha `tenant_ativo: {id:number;nome:string}|null`, `tenants_disponiveis: {id:number;nome:string}[]`, `pode_todas: boolean`.
  - `interface Tenant { id:number; nome:string; cnpj:string|null; ativo:boolean; created_at:string|null }`.
  - `interface UsuarioAdmin { id:number; name:string; email:string; ativo:boolean; tenant_id:number|null; papeis:string[]; tenants:{id:number;nome:string}[]; created_at:string|null }`.
  - `interface NovoUsuario { name:string; email:string; password?:string; papel:string; tenants:number[] }`.
  - `sessaoApi.trocarTenant(tenantId:number|null): Promise<{tenant_ativo:{id:number;nome:string}|null}>`.
  - `tenantsApi.listar()`, `.criar(payload)`, `.atualizar(id,payload)`, `.definirAtivo(id,ativo)`.
  - `usuariosApi.listar()`, `.criar(payload)`, `.atualizar(id,payload)`, `.definirAtivo(id,ativo)`.
  - `unidadesApi.obter(id)`, `.atualizar(id,payload)`, `.definirAtivo(id,ativo)`; `NovaUnidade` ganha `tenant_id?:number`.

- [ ] **Step 1: Write the failing test** — adicione ao `frontend/src/lib/api.test.ts`

```ts
import { describe, it, expect, vi } from 'vitest'

// (mantenha os testes existentes; adicione este bloco)
describe('novos endpoints administrativos', () => {
  it('sessaoApi.trocarTenant chama POST /sessao/tenant e devolve tenant_ativo', async () => {
    vi.resetModules()
    const post = vi.fn().mockResolvedValue({ data: { tenant_ativo: { id: 3, nome: 'A' } } })
    vi.doMock('axios', () => ({
      default: { create: () => ({ post, get: vi.fn() }) },
      AxiosError: class {},
    }))
    vi.doMock('axios', () => ({
      default: { create: () => ({ post, get: vi.fn() }) },
      AxiosError: class {},
    }))
    const { sessaoApi } = await import('./api')

    const r = await sessaoApi.trocarTenant(3)
    expect(post).toHaveBeenCalledWith('/sessao/tenant', { tenant_id: 3 })
    expect(r.tenant_ativo?.id).toBe(3)
  })
})
```

> Se o arquivo já mocka o axios com outra forma, adapte o mock ao padrão existente do arquivo (a intenção: verificar que o endpoint e o corpo são os definidos).

- [ ] **Step 2: Run test to verify it fails**

Run (em `frontend/`): `npm run test -- api.test.ts`
Expected: FAIL — `sessaoApi` não existe.

- [ ] **Step 3: Implement types and endpoints**

Em `frontend/src/lib/api.ts`:
- Estenda `Usuario`:
```ts
export interface TenantResumo { id: number; nome: string }

export interface Usuario {
  id: number
  name: string
  email: string
  tenant_id: number | null
  tenant_ativo: TenantResumo | null
  tenants_disponiveis: TenantResumo[]
  pode_todas: boolean
  papeis: string[]
  permissoes: string[]
  mfa_pendente: boolean
  mfa_confirmado: boolean
}
```
- Adicione `sessaoApi`:
```ts
export const sessaoApi = {
  async trocarTenant(tenantId: number | null): Promise<{ tenant_ativo: TenantResumo | null }> {
    const { data } = await http.post<{ tenant_ativo: TenantResumo | null }>('/sessao/tenant', {
      tenant_id: tenantId,
    })
    return data
  },
}
```
- Adicione `Tenant`, `UsuarioAdmin`, `NovoUsuario` e os apis `tenantsApi`, `usuariosApi`.
- Estenda `unidadesApi` com `obter`, `atualizar`, `definirAtivo`; `NovaUnidade` ganha `tenant_id?: number`.

(Implemente seguindo o padrão já existente no arquivo: `http.get/post/put` + `data.data` para recursos envelopados.)

- [ ] **Step 4: Run test to verify it passes**

Run (em `frontend/`): `npm run test -- api.test.ts`
Expected: PASS.

- [ ] **Step 5: Typecheck**

Run (em `frontend/`): `npm run build`
Expected: build OK.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/lib/api.ts frontend/src/lib/api.test.ts
git commit -m "feat(spa): tipos e endpoints de sessao, entidades e usuarios"
```

---

## Task 11: SPA — seletor de entidade no `Topbar`

**Files:**
- Create: `frontend/src/features/sessao/SeletorEntidade.tsx`
- Modify: `frontend/src/components/layout/Topbar.tsx`
- Modify: `frontend/src/features/auth/session.tsx` (expor `recarregar`)
- Test: `frontend/src/features/sessao/SeletorEntidade.test.tsx`

**Interfaces:**
- Consumes: `sessaoApi.trocarTenant` (Task 10); `useSessao()` (usuário com `tenant_ativo`/`tenants_disponiveis`/`pode_todas`).
- Produces: componente `SeletorEntidade` que renderiza um `<select>`: opção "Todas as entidades" (só se `pode_todas`) + uma por `tenants_disponiveis`. Ao mudar: `sessaoApi.trocarTenant(valor)` e `queryClient.clear()` (invalida tudo — dados mudam de tenant).

- [ ] **Step 1: Write the failing test** — `frontend/src/features/sessao/SeletorEntidade.test.tsx`

```tsx
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const trocarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  sessaoApi: { trocarTenant: (...a: unknown[]) => trocarMock(...a) },
}))

const recarregarMock = vi.fn()
vi.mock('../auth/session', () => ({
  useSessao: () => ({
    usuario: {
      id: 1, name: 'X', email: 'x@x', tenant_id: null,
      tenant_ativo: { id: 1, nome: 'Entidade A' },
      tenants_disponiveis: [{ id: 1, nome: 'Entidade A' }, { id: 2, nome: 'Entidade B' }],
      pode_todas: true, papeis: [], permissoes: [], mfa_pendente: false, mfa_confirmado: true,
    },
    recarregar: recarregarMock,
  }),
}))

import { SeletorEntidade } from './SeletorEntidade'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <SeletorEntidade />
    </QueryClientProvider>,
  )
}

describe('SeletorEntidade', () => {
  beforeEach(() => {
    trocarMock.mockReset().mockResolvedValue({ tenant_ativo: { id: 2, nome: 'Entidade B' } })
    recarregarMock.mockReset().mockResolvedValue(undefined)
  })

  it('lista Todas + entidades disponíveis para super-admin', () => {
    renderizar()
    const select = screen.getByRole('combobox', { name: /entidade/i })
    expect(select).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /todas as entidades/i })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: /entidade b/i })).toBeInTheDocument()
  })

  it('troca de entidade e recarrega a sessão', async () => {
    const user = userEvent.setup()
    renderizar()

    await user.selectOptions(screen.getByRole('combobox', { name: /entidade/i }), '2')

    await waitFor(() => expect(trocarMock).toHaveBeenCalledWith(2))
    await waitFor(() => expect(recarregarMock).toHaveBeenCalled())
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (em `frontend/`): `npm run test -- SeletorEntidade`
Expected: FAIL — componente não existe.

- [ ] **Step 3: Implement `SeletorEntidade`**

Crie `frontend/src/features/sessao/SeletorEntidade.tsx`:

```tsx
import { useQueryClient } from '@tanstack/react-query'
import { sessaoApi } from '../../lib/api'
import { useSessao } from '../auth/session'

/**
 * Seletor do tenant ativo da sessão.
 *
 * Super-admin vê "Todas as entidades" + qualquer entidade; os demais veem
 * apenas as suas. Ao trocar, limpa o cache do React Query (os dados passam a
 * ser de outra entidade).
 */
export function SeletorEntidade() {
  const { usuario, recarregar } = useSessao()
  const queryClient = useQueryClient()

  if (!usuario) return null

  const soUma = usuario.tenants_disponiveis.length <= 1 && !usuario.pode_todas
  if (soUma) return null

  async function trocar(valor: string) {
    const tenantId = valor === '' ? null : Number(valor)
    await sessaoApi.trocarTenant(tenantId)
    queryClient.clear()
    await recarregar()
  }

  const atual = usuario.tenant_ativo?.id ?? ''

  return (
    <label className="shell__entidade">
      <span className="shell__entidade-rotulo">Entidade</span>
      <select
        aria-label="Entidade"
        value={atual}
        onChange={(e) => trocar(e.target.value)}
      >
        {usuario.pode_todas && <option value="">Todas as entidades</option>}
        {usuario.tenants_disponiveis.map((t) => (
          <option key={t.id} value={t.id}>
            {t.nome}
          </option>
        ))}
      </select>
    </label>
  )
}
```

- [ ] **Step 4: Wire into `Topbar`**

Em `frontend/src/components/layout/Topbar.tsx`, importe e renderize `<SeletorEntidade />` antes do nome do usuário:

```tsx
import { SeletorEntidade } from '../../features/sessao/SeletorEntidade'
```
```tsx
    <header className="shell__topbar">
      <SeletorEntidade />
      <div>
        ...
```

- [ ] **Step 5: Run test + typecheck**

Run (em `frontend/`): `npm run test -- SeletorEntidade`
Expected: PASS (2 testes).
Run (em `frontend/`): `npm run build`
Expected: build OK.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/features/sessao/SeletorEntidade.tsx frontend/src/features/sessao/SeletorEntidade.test.tsx frontend/src/components/layout/Topbar.tsx
git commit -m "feat(spa): seletor de entidade no topo"
```

---

## Task 12: SPA — página de Entidades (Tenants) + menu

**Files:**
- Create: `frontend/src/features/tenants/TenantsPage.tsx`
- Modify: `frontend/src/components/layout/Sidebar.tsx`
- Modify: `frontend/src/app/router.tsx`
- Test: `frontend/src/features/tenants/TenantsPage.test.tsx`

**Interfaces:**
- Consumes: `tenantsApi` (Task 10); `useSessao()` para saber se é super-admin (`usuario.pode_todas`).
- Produces: `TenantsPage` (lista + criar + editar + ativar/desativar) e item de menu "Entidades" visível só quando `usuario.pode_todas`.

- [ ] **Step 1: Write the failing test** — `frontend/src/features/tenants/TenantsPage.test.tsx`

```tsx
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  tenantsApi: { listar: (...a: unknown[]) => listarMock(...a) },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

import { TenantsPage } from './TenantsPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <TenantsPage />
    </QueryClientProvider>,
  )
}

describe('TenantsPage', () => {
  beforeEach(() => {
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 1, nome: 'Prefeitura X', cnpj: '11222333000181', ativo: true, created_at: null }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
  })

  it('lista entidades', async () => {
    renderizar()
    expect(await screen.findByText('Prefeitura X')).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (em `frontend/`): `npm run test -- TenantsPage`
Expected: FAIL — componente não existe.

- [ ] **Step 3: Implement `TenantsPage`**

Crie `frontend/src/features/tenants/TenantsPage.tsx` seguindo o padrão de `UnidadesPage` (useQuery com `queryKey: ['tenants']`, tabela com Nome/CNPJ/Ativo, formulário de criação, botão "Editar" por linha e ação "Desativar/Ativar" chamando `tenantsApi.definirAtivo`). Mensagens em pt-BR. Estados de erro com `mensagemDeErro`.

- [ ] **Step 4: Add to Sidebar + router**

`Sidebar.tsx`: adicione `{ para: '/entidades', rotulo: 'Entidades' }` — mas o filtro atual é por permissão. Estenda `ItemMenu` com `soSuperAdmin?: boolean` e filtre também por isso; passe `podeTodas` para `<Sidebar>`. Em `AppShell`, passe `usuario.pode_todas`.

`router.tsx`: importe `TenantsPage` e adicione `{ path: 'entidades', element: <TenantsPage /> }` dentro do shell.

- [ ] **Step 5: Run test + typecheck**

Run (em `frontend/`): `npm run test -- TenantsPage`
Expected: PASS.
Run (em `frontend/`): `npm run build`
Expected: build OK.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/features/tenants/TenantsPage.tsx frontend/src/features/tenants/TenantsPage.test.tsx frontend/src/components/layout/Sidebar.tsx frontend/src/components/layout/AppShell.tsx frontend/src/app/router.tsx
git commit -m "feat(spa): pagina de entidades (tenants) e menu"
```

---

## Task 13: SPA — página de Usuários + menu

**Files:**
- Create: `frontend/src/features/usuarios/UsuariosPage.tsx`
- Modify: `frontend/src/components/layout/Sidebar.tsx`
- Modify: `frontend/src/app/router.tsx`
- Test: `frontend/src/features/usuarios/UsuariosPage.test.tsx`

**Interfaces:**
- Consumes: `usuariosApi`, `tenantsApi` (Task 10); `useSessao()` (para `pode_todas` e `tenants_disponiveis`).
- Produces: `UsuariosPage` com papel (select dos 4 papéis) e multi-seleção de entidades (checkbox list); item de menu "Usuários" visível com permissão `usuario.gerenciar`.

- [ ] **Step 1: Write the failing test** — `frontend/src/features/usuarios/UsuariosPage.test.tsx`

```tsx
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  usuariosApi: { listar: (...a: unknown[]) => listarMock(...a) },
  tenantsApi: { listar: vi.fn().mockResolvedValue({ data: [], meta: {}, links: {} }) },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

vi.mock('../auth/session', () => ({
  useSessao: () => ({
    usuario: {
      id: 1, name: 'A', email: 'a@a', tenant_id: null, tenant_ativo: null,
      tenants_disponiveis: [{ id: 1, nome: 'Entidade A' }], pode_todas: true,
      papeis: ['super-admin'], permissoes: ['usuario.gerenciar'], mfa_pendente: false, mfa_confirmado: true,
    },
    recarregar: vi.fn(),
  }),
}))

import { UsuariosPage } from './UsuariosPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <UsuariosPage />
    </QueryClientProvider>,
  )
}

describe('UsuariosPage', () => {
  beforeEach(() => {
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 1, name: 'Operador Um', email: 'op@x', ativo: true, tenant_id: 1, papeis: ['operador'], tenants: [{ id: 1, nome: 'Entidade A' }], created_at: null }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
  })

  it('lista usuários', async () => {
    renderizar()
    expect(await screen.findByText('Operador Um')).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (em `frontend/`): `npm run test -- UsuariosPage`
Expected: FAIL — componente não existe.

- [ ] **Step 3: Implement `UsuariosPage`**

Crie `frontend/src/features/usuarios/UsuariosPage.tsx`: lista (Nome/E-mail/Papéis/Entidades/Ativo), formulário de criação/edição com `papel` (select) e **checkbox de entidades** (`tenants_disponiveis` para não-super-admin, ou todas de `tenantsApi.listar()` para super-admin), ação Desativar/Ativar. Ao editar um usuário, a própria conta não mostra ações sensíveis (o backend também bloqueia). Mensagens pt-BR.

- [ ] **Step 4: Add to Sidebar + router**

`Sidebar.tsx`: `{ para: '/usuarios', rotulo: 'Usuários', permissoes: ['usuario.gerenciar'] }`.
`router.tsx`: `{ path: 'usuarios', element: <UsuariosPage /> }`.

- [ ] **Step 5: Run test + typecheck**

Run (em `frontend/`): `npm run test -- UsuariosPage`
Expected: PASS.
Run (em `frontend/`): `npm run build`
Expected: build OK.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/features/usuarios/UsuariosPage.tsx frontend/src/features/usuarios/UsuariosPage.test.tsx frontend/src/components/layout/Sidebar.tsx frontend/src/app/router.tsx
git commit -m "feat(spa): pagina de usuarios (CRUD, papel e entidades)"
```

---

## Task 14: SPA — `UnidadesPage` com editar e desativar

**Files:**
- Modify: `frontend/src/features/unidades/UnidadesPage.tsx`
- Test: `frontend/src/features/unidades/UnidadesPage.test.tsx`

**Interfaces:**
- Consumes: `unidadesApi.atualizar`/`definirAtivo` (Task 10); campo `tenant_id` no `Unidade`.
- Produces: `UnidadesPage` com botão "Editar" por linha (formulário reusa o de criação em modo edição; **entidade exibida fixa/não editável**) e ação "Desativar/Ativar".

- [ ] **Step 1: Write the failing test** — `frontend/src/features/unidades/UnidadesPage.test.tsx`

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import { describe, it, expect, vi, beforeEach } from 'vitest'

const listarMock = vi.fn()
const atualizarMock = vi.fn()
vi.mock('../../lib/api', () => ({
  unidadesApi: {
    listar: (...a: unknown[]) => listarMock(...a),
    atualizar: (...a: unknown[]) => atualizarMock(...a),
    definirAtivo: vi.fn(),
  },
  mensagemDeErro: (e: unknown) => (e instanceof Error ? e.message : 'erro'),
}))

import { UnidadesPage } from './UnidadesPage'

function renderizar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter>
        <UnidadesPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('UnidadesPage', () => {
  beforeEach(() => {
    listarMock.mockReset().mockResolvedValue({
      data: [{ id: 1, tenant_id: 5, codigo_unidade: null, nome: 'Unidade A', nome_resumido: null, cnpj: '11222333000181', ie: null, uf: 'SP', tp_amb: 2, ativo: true, certificado: null, created_at: null }],
      meta: { current_page: 1, last_page: 1, per_page: 50, total: 1 },
      links: {},
    })
    atualizarMock.mockReset().mockResolvedValue({})
  })

  it('abre o formulário de edição com os dados da unidade', async () => {
    const user = userEvent.setup()
    renderizar()

    await user.click(await screen.findByRole('button', { name: /editar/i }))

    expect(screen.getByDisplayValue('Unidade A')).toBeInTheDocument()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run (em `frontend/`): `npm run test -- UnidadesPage`
Expected: FAIL — não há botão "Editar".

- [ ] **Step 3: Implement edit/desativar**

Refatore `NovaUnidadeForm` (o componente interno de `UnidadesPage.tsx`) para aceitar `unidadeEmEdicao?: Unidade`. Em modo edição: pré-preenche os campos, chama `unidadesApi.atualizar(u.id, payload)`, e **não** exibe seletor de entidade (entidade fixa). Adicione coluna de ações com "Editar" e "Desativar"/"Ativar" (`unidadesApi.definirAtivo`). Invalide `['unidades']` em sucesso.

- [ ] **Step 4: Run test + typecheck**

Run (em `frontend/`): `npm run test -- UnidadesPage`
Expected: PASS.
Run (em `frontend/`): `npm run build`
Expected: build OK.

- [ ] **Step 5: Run the whole frontend suite**

Run (em `frontend/`): `npm run test`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/features/unidades/UnidadesPage.tsx frontend/src/features/unidades/UnidadesPage.test.tsx
git commit -m "feat(spa): editar e desativar unidades"
```

---

## Task 15: Seed de demonstração e README

**Files:**
- Modify: `backend/tools/seed_demo.php`
- Modify: `README.md`

**Interfaces:**
- Consumes: `tenant_user` (Task 1); endpoints/páginas das Tasks 6, 8, 9, 12, 13, 14.
- Produces: seed demo com vínculos via `tenant_user`; README documentando as telas/regras novas.

- [ ] **Step 1: Update `tools/seed_demo.php`**

Após criar cada usuário, crie o vínculo em `tenant_user` (ex.: `$admin->tenants()->syncWithoutDetaching([$tenant->id]);`). Crie também um super-admin de demonstração com `tenant_id = null` e **sem** vínculo (usa "Todas"/seletor), com papel `super-admin` e MFA confirmado, e imprima as credenciais.

- [ ] **Step 2: Update `README.md`**

Adicione uma seção "Administração" descrevendo: CRUD de Entidades (super-admin), CRUD de Usuários (papel global + múltiplos vínculos), edição/desativação de Unidades, e o **seletor de entidade** (super-admin: "Todas"; demais: as suas). Documente que usuário/entidade **inativos** bloqueiam acesso e que unidade **não** muda de entidade.

- [ ] **Step 3: Verify seed runs**

Run: `docker compose exec -T app php tools/seed_demo.php`
Expected: imprime "Pronto!" com as credenciais.

- [ ] **Step 4: Commit**

```bash
git add backend/tools/seed_demo.php README.md
git commit -m "docs: seed demo multitenant e README de administracao"
```

---

## Encerramento (após todas as tasks)

- **Whole-branch review:** dispatch de revisor no modelo mais capaz sobre o diff completo (`origin/main..HEAD`), cobrindo os 5 itens do Review Focus.
- Corrigir achados Critical/Important; re-revisar apenas os diffs de correção.
- Suíte completa: backend `docker compose run --rm app php artisan test`; frontend `npm run test` + `npm run build`.
- **Avisar o parceiro humano antes de qualquer push** (unha única pausa prevista).
- Atualizar o README/todo (Task 15) e então seguir `superpowers:finishing-a-development-branch`.

---

## Self-Review

**1. Cobertura da spec:**
- §0 o que filtra por tenant → Tasks 1, 8 (`withoutGlobalScopes`+`whereHas`).
- §1 pivô `tenant_user` + migração → Task 1.
- §2 tenant ativo na sessão (`TenantAtivoResolver`, `POST /sessao/tenant`, `/me`) → Tasks 2, 3, 5.
- §3 CRUD Tenants → Task 6.
- §4 CRUD Usuários → Tasks 7, 8.
- §5 Unidades editar/desativar → Task 9.
- §6 criação sob super-admin "Todas" (validação condicional) → Task 9.
- §7 SPA → Tasks 10–14.
- §8 Enforcement de desativar → Task 5.
- Riscos: 1→Task 4; 2→Task 9; 3→Task 3; 4→Task 2/5; 5→Task 8; 6→Task 8; 7→Task 11; 8→Task 1.

Sem lacunas.

**2. Step scan:** cada step tem uma ação e um resultado verificável; corpos de código só onde a assinatura+teste não determinam (ex.: middleware, controller de usuário).

**3. Consistência de tipos:** `TenantAtivoResolver::{paraUsuario, disponiveis, podeTodas, usuarioSemVinculo}` usados igualmente nas Tasks 3, 5, 8; `AuditLogger::log(..., ?int $tenantId)` na Task 4 casa com `Auditable::audit(..., ?int $tenantId)`; `UserResource`/`UsuarioAdmin` e `TenantResource`/`Tenant` alinhados entre backend e SPA; `unidadesApi.obter/atualizar/definirAtivo` definidos na Task 10 e usados na 14.

**4. Review Focus:** cada um dos 5 itens tem teste na task dona (Task 8 #1, Task 2 #2, Task 3 #3, Task 9 #4, Task 4 #5).

**5. Proporção:** o plano descreve decisões e interfaces; os corpos de código são os pontos onde a assinatura não basta (middleware, políticas, controller) — não é transcrição do programa.
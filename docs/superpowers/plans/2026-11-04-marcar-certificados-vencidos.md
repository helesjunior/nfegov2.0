# Marcação explícita de certificados vencidos — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tornar explícito no banco o estado "certificado e-CNPJ vencido", sem apagar a informação operacional (dashboard, alertas por e-mail e tela da unidade continuam mostrando o vencido).

**Architecture:** Adicionar um campo nullable `expirado_em` na tabela `certificates`. O campo é preenchido por um novo comando diário (`certificate-expiry:mark`) que varre certificados ativos com `valido_ate` no passado. `ativo` **não** é alterado: continua significando "é o certificado corrente da unidade" (rotação). Assim, o estado vencido fica gravado de forma idempotente e reversível, e os consumidores existentes (`DashboardController`, `CheckCertificateExpiry`, `CertificateController::show`) seguem funcionando — agora com a informação explícita disponível.

**Tech Stack:** Laravel 13, PHP 8.4, PostgreSQL, Carbon. Comando agendado em `routes/console.php` (ou no kernel conforme padrão do projeto).

**Spec:** Esta conversa (decisão do usuário: "Campo `expirado_em` (Recomendado)").

## Global Constraints

- Idioma do código/comentários: português do Brasil, seguindo o estilo do repositório.
- Multitenancy: qualquer query nova deve respeitar/limpar o global scope de tenant explicitamente (o comando roda global → `TenantContext::clear()`).
- `certificates.valido_ate` é `NOT NULL`; `expirado_em` é nullable.
- Sem alterar o significado de `ativo` (rotação continua sendo o único uso).
- TDD: teste que falha (RED) → implementação mínima (GREEN) → refatorar.
- Ambiente de teste: PostgreSQL `nfegov_test`, via `docker compose run --rm app php artisan test`.
- Antes de qualquer push: avisar o usuário.

## Review Focus

- Certificado **sem** `valido_ate` válido: não deve ser marcado nem quebrar (não ocorre hoje, mas o comando não pode explodir).
- Certificado **já marcado** (`expirado_em` preenchido): o comando não pode remarcar/alterar o carimbo original (idempotência).
- Certificado que vence **depois** do comando rodar: nada a fazer naquele ciclo.

---

### Task 1: Campo `expirado_em` no certificado

**Files:**
- Create: `backend/database/migrations/2026_11_04_120000_add_expirado_em_to_certificates_table.php`
- Modify: `backend/app/Models/Certificate.php` (casts + helper)
- Test: `backend/tests/Unit/Models/CertificateTest.php` (criar se não existir) ou o teste de model existente

**Interfaces:**
- Produces: coluna `certificates.expirado_em` (`datetime`, nullable); cast `'expirado_em' => 'datetime'`; método `Certificate::foiExpirado(): bool` (true quando `expirado_em` não é null).

- [ ] **Step 1: Escrever o teste que falha**

No teste de model, adicionar:

```php
public function test_expirado_em_e_castado_para_datetime(): void
{
    $cert = Certificate::factory()->create(['expirado_em' => now()->subDay()]);

    $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $cert->expirado_em);
    $this->assertTrue($cert->foiExpirado());
}

public function test_foi_expirado_e_falso_por_padrao(): void
{
    $cert = Certificate::factory()->create();

    $this->assertNull($cert->expirado_em);
    $this->assertFalse($cert->foiExpirado());
}
```

- [ ] **Step 2: Rodar e verificar que falha**

Run: `docker compose run --rm app php artisan test --filter=CertificateTest`
Expected: FAIL — coluna/método inexistentes.

- [ ] **Step 3: Criar a migration**

`2026_11_04_120000_add_expirado_em_to_certificates_table.php`:

```php
Schema::table('certificates', function (Blueprint $table) {
    $table->dateTime('expirado_em')->nullable()->after('substituido_em');
});
```

`down()`: `$table->dropColumn('expirado_em');`

- [ ] **Step 4: Adicionar cast e helper no model**

Em `Certificate`:
- `casts`: `'expirado_em' => 'datetime'`.
- Método:

```php
public function foiExpirado(): bool
{
    return $this->expirado_em !== null;
}
```

- [ ] **Step 5: Rodar os testes e confirmar que passam**

Run: `docker compose run --rm app php artisan test --filter=CertificateTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations backend/app/Models/Certificate.php backend/tests
git commit -m "feat(certificate): add expirado_em column and helper"
```

---

### Task 2: Comando `certificate-expiry:mark`

**Files:**
- Create: `backend/app/Console/Commands/MarkExpiredCertificates.php`
- Modify: `backend/routes/console.php` (agendamento diário)
- Test: `backend/tests/Feature/Certificate/MarkExpiredCertificatesTest.php`

**Interfaces:**
- Consumes: `Certificate::expirado_em` (Task 1).
- Produces: comando de assinatura `certificate-expiry:mark`, saída informativa com o total marcado; retorna `self::SUCCESS`.

- [ ] **Step 1: Escrever o teste que falha**

Reaproveitar o padrão de `backend/tests/Feature/Certificate/ExpiryAlertTest.php` (que já usa `$this->artisan('certificate-alerts:check')` e `withoutGlobalScopes()`). Novos testes para o comando novo:

```php
public function test_marca_certificado_ativo_vencido(): void
{
    $cert = Certificate::factory()->create(['ativo' => true, 'valido_ate' => now()->subDay(), 'expirado_em' => null]);

    $this->artisan('certificate-expiry:mark')->assertSuccessful();

    $this->assertNotNull($cert->fresh()->expirado_em);
}

public function test_nao_marca_certificado_vigente(): void
{
    $cert = Certificate::factory()->create(['ativo' => true, 'valido_ate' => now()->addDays(30)]);

    $this->artisan('certificate-expiry:mark')->assertSuccessful();

    $this->assertNull($cert->fresh()->expirado_em);
}

public function test_nao_reaplica_carimbo_em_certificado_ja_marcado(): void
{
    $original = now()->subDays(10);
    $cert = Certificate::factory()->create([
        'ativo' => true, 'valido_ate' => now()->subDay(), 'expirado_em' => $original,
    ]);

    $this->artisan('certificate-expiry:mark')->assertSuccessful();

    $this->assertEquals($original->toDateTimeString(), $cert->fresh()->expirado_em->toDateTimeString());
}

public function test_nao_marca_certificado_inativo_vencido(): void
{
    $cert = Certificate::factory()->create(['ativo' => false, 'valido_ate' => now()->subDay(), 'expirado_em' => null]);

    $this->artisan('certificate-expiry:mark')->assertSuccessful();

    $this->assertNull($cert->fresh()->expirado_em);
}
```

Observação: cada teste deve limpar o contexto de tenant como o `CheckCertificateExpiry` faz (`TenantContext::clear()`) via `withoutGlobalScopes()` nas asserções; o comando roda global.

- [ ] **Step 2: Rodar e verificar que falha**

Run: `docker compose run --rm app php artisan test --filter=MarkExpiredCertificatesTest`
Expected: FAIL — comando inexistente.

- [ ] **Step 3: Implementar o comando**

`MarkExpiredCertificates`:
- Assinatura `certificate-expiry:mark`, descrição "Marca como expirados os certificados e-CNPJ vencidos".
- `handle()`: `TenantContext::clear();` depois atualizar em massa apenas os ainda não marcados:

```php
$total = Certificate::withoutGlobalScopes()
    ->where('ativo', true)
    ->whereNull('expirado_em')
    ->whereNotNull('valido_ate')
    ->where('valido_ate', '<', now())
    ->update(['expirado_em' => now()]);

$this->info("Certificados marcados como expirados: {$total}");
return self::SUCCESS;
```

- [ ] **Step 4: Agendar diariamente**

Em `backend/routes/console.php`, seguindo o padrão existente (`Schedule::command(CheckCertificateExpiry::class)->dailyAt('07:00');`):

```php
Schedule::command(MarkExpiredCertificates::class)->dailyAt('07:05');
```

(logo após os alertas das 07:00 — os alertas devem continuar refletindo o estado "vencendo"; a marcação explícita vem depois).

- [ ] **Step 5: Rodar e confirmar verde**

Run: `docker compose run --rm app php artisan test --filter=MarkExpiredCertificatesTest`
Expected: PASS (4 testes).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Console/Commands backend/routes/console.php backend/tests
git commit -m "feat(certificate): daily command to mark expired certificates explicitly"
```

---

### Task 3: Expor o estado na tela da unidade e no dashboard

**Files:**
- Modify: `backend/app/Http/Controllers/Api/CertificateController.php` (`metadados()`)
- Modify: `backend/app/Http/Controllers/Api/DashboardController.php` (contagem `expirados`)
- Test: testes de feature correspondentes

**Interfaces:**
- Consumes: `Certificate::expirado_em` / `foiExpirado()` (Task 1).

- [ ] **Step 1: Escrever o teste que falha**

- Em `CertificateController` test: `GET /api/unidades/{id}/certificado` deve incluir `expirado_em` e `foi_expirado` no JSON de metadados.
- Em `DashboardController` test: incluir a chave `certificados.expirados` contando certificados com `expirado_em` não nulo (mantendo `vencidos` como está, para não quebrar contrato).

- [ ] **Step 2: Rodar e verificar que falha**

Run: `docker compose run --rm app php artisan test --filter=CertificateControllerTest` (e o de dashboard)
Expected: FAIL — chaves ausentes.

- [ ] **Step 3: Implementar**

- `metadados()`: adicionar `'expirado_em' => $cert->expirado_em, 'foi_expirado' => $cert->foiExpirado(),`.
- `DashboardController`: adicionar `'expirados' => Certificate::whereNotNull('expirado_em')->count(),` dentro de `certificados`.

- [ ] **Step 4: Rodar e confirmar verde**

Run: `docker compose run --rm app php artisan test --filter=Certificate`
Expected: PASS.

- [ ] **Step 5: Atualizar o README** (seção de operação) descrevendo a marcação explícita e o comando.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers backend/tests README.md
git commit -m "feat(certificate): expose expirado_em in API and dashboard"
```

---

### Task 4: Verificação final e documentação

- [ ] **Step 1: Rodar a suíte backend completa**

Run: `docker compose run --rm app php artisan test`
Expected: todos verdes (baseline anterior: 123 passed).

- [ ] **Step 2: Confirmar `ativo` intacto**

Verificar que nenhum teste/consumidor passou a depender de `ativo=false` para vencidos.

- [ ] **Step 3: Avisar o usuário e, com o aval, push.**

```bash
git push origin main
```
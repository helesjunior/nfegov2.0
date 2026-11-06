# Correção da recuperação manual de NF-e (cStat 137) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer a recuperação manual por chave encontrar uma NF-e que está na fila do distDFe como `resNFe`, enviando a Ciência e recorrendo à varredura incremental por `ultNSU` — sem nunca alterar o cursor `nsu.ultimo_nsu`.

**Architecture:** `RecuperacaoService::porChave` ganha um fallback: (1) `consChNFe`; se `137`, (2) envia Ciência (210210) e reconsulta `consChNFe`; se ainda `137`, (3) varre a fila incremental por `ultNSU` do cursor local procurando a chave. O `RecuperarNfeJob` também enfileira um `SyncNfeJob` da unidade para consumir a fila pelo caminho normal. As mensagens de notificação passam a distinguir "Ciência enviada, aguardando propagação" de "não disponível na fila".

**Tech Stack:** Laravel 12, PHP 8.3, sped-nfe, Mockery/PHPUnit, React + Vitest.

**Spec:** `docs/superpowers/specs/2026-11-06-diagnostico-recuperacao-nfe-137.md` (diagnóstico) e `docs/superpowers/specs/2026-11-06-recuperacao-manual-nfe-design.md` (design da feature original).

## Global Constraints

- A recuperação **NUNCA** altera `nsu.ultimo_nsu` (nem cria linha `Nsu`).
- Só NF-e; eventos (`resEvento`) permanecem ignorados.
- Sem libs novas.
- Multitenancy: banco único, `tenant_id` + global scope; gravação sempre no tenant/unidade do contexto.
- Execução assíncrona (fila `sefaz`), notificação ao final.
- Evitar rajadas: respeitar `SefazGuard` (circuit breaker + rate limiter) e `sleep` entre varreduras.
- NSU 0 é inválido (sped-nfe cai para varredura incremental).
- Comandos: testes `docker compose exec -T app php artisan test`; Pint `docker compose exec -T app ./vendor/bin/pint <arquivos>`; reiniciar Horizon `docker compose restart horizon`.

## Review Focus

- **Chave que não está na fila (nunca existiu ou > 60 dias):** por chave, com Ciência enviada e varredura completa, deve terminar em `NAO_ENCONTRADO` — e a mensagem deve dizer que a SEFAZ não tem o documento, não "aguarde a Ciência".
- **Varredura incremental numa unidade com cursor muito atrás:** pode consumir muitas iterações; deve parar em `maxNSU` e respeitar `max_iteracoes`/`sleep`, sem gerar 656.
- **Chave encontrada na varredura como `resNFe`:** deve importar o resumo, enviar Ciência, baixar o completo e virar `ENCONTRADO`.
- **Falha de certificado/circuito no meio:** o job deve notificar `BLOQUEADO` e não tocar a SEFAZ.
- **Nota já existente no banco:** reimportação idempotente por `chave` (sem duplicar).

---

### Task 1: Cliente SEFAZ — varredura incremental exposta com filtro por chave

**Files:**
- Modify: `backend/app/Domain/Sefaz/SefazClient.php`
- Modify: `backend/app/Domain/Sefaz/SpedSefazClient.php`
- Test: `backend/tests/Unit/Sefaz/SpedSefazClientTest.php`

**Interfaces:**
- Produces: `SefazClient::distDFe(Unidade $unidade, int $ultimoNsu): string` (já existe — reaproveitado).

- [ ] **Step 1: Escrever o teste que falha**

Adicione a `SpedSefazClientTest`:

```php
public function test_dist_dfe_incremental_usa_a_fonte_an(): void
{
    // Já coberto por distDFeCom; garante que distDFe não regride para fonte vazia.
    // (Se já existir um teste equivalente, apenas confirme a cobertura.)
}
```

*(Se a cobertura de `distDFe` já existir, este passo é uma verificação, não um novo teste.)*

- [ ] **Step 2: Verificar**

Run: `docker compose exec -T app php artisan test --filter=SpedSefazClientTest`
Expected: PASS (nenhuma mudança de código aqui; `distDFe` já existe).

---

### Task 2: `ManifestacaoService` — expor se a Ciência foi enviada agora

**Files:**
- Modify: `backend/app/Domain/Sefaz/ManifestacaoService.php`
- Test: `backend/tests/Feature/Sefaz/ManifestacaoServiceTest.php` (criar se não existir)

**Interfaces:**
- Produces: `ManifestacaoService::ciencia(Unidade $unidade, string $chave): bool` (idempotente; já existe).

- [ ] **Step 1: Escrever o teste que falha**

```php
public function test_ciencia_nao_reenvia_quando_ja_manifestado(): void
{
    // Cria uma Manifestacao(210210) e garante que o client não é chamado.
    $client = \Mockery::mock(SefazClient::class);
    $client->shouldNotReceive('manifestarCiencia');
    app()->instance(SefazClient::class, $client);

    $ok = app(ManifestacaoService::class)->ciencia($unidade, $chave);

    $this->assertTrue($ok);
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T app php artisan test --filter=ManifestacaoServiceTest`
Expected: FAIL (teste ainda não existe → será GREEN direto se o comportamento já for esse; ajustar).

- [ ] **Step 3: Implementar (se necessário)**

Nenhuma mudança de código esperada — `ciencia()` já é idempotente.

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T app php artisan test --filter=ManifestacaoServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Feature/Sefaz/ManifestacaoServiceTest.php
git commit -m "test: cobre idempotência da Ciência da Operação"
```

---

### Task 3: `RecuperacaoService::porChave` — Ciência + reconsulta + fallback incremental

**Files:**
- Modify: `backend/app/Domain/Sefaz/RecuperacaoService.php`
- Modify: `backend/app/Domain/Sefaz/ResultadoRecuperacao.php` (novo status `AGUARDANDO` se necessário)
- Test: `backend/tests/Feature/Sefaz/RecuperacaoServiceTest.php`

**Interfaces:**
- Consumes: `ManifestacaoService::ciencia(Unidade, string): bool`; `SefazClient::distDFe(Unidade, int): string`; `SefazClient::distDFePorChave(Unidade, string): string`; `EnvelopeDistDfe::parse(string): array`.
- Produces: `RecuperacaoService::porChave(Unidade, string): ResultadoRecuperacao` (contrato inalterado).

- [ ] **Step 1: Escrever os testes que falham**

Em `RecuperacaoServiceTest`, adicione:

```php
public function test_por_chave_com_137_envia_ciencia_e_reconsulta(): void
{
    // 1ª consChNFe → 137; então ciencia(); 2ª consChNFe → 138 com resNFe.
    // Asserção: resultado ENCONTRADO e manifesto criado.
}

public function test_por_chave_fallback_varredura_incremental_encontra_a_nota(): void
{
    // consChNFe sempre 137; ciencia() ok; distDFe(23921) → 138 com resNFe da chave.
    // Asserção: ENCONTRADO; nsu.ultimo_nsu permanece 23921 (INALTERADO).
}

public function test_por_chave_sem_documento_apos_ciencia_e_varredura_retorna_nao_encontrado(): void
{
    // consChNFe 137; ciencia ok; distDFe 137 em toda a faixa.
    // Asserção: NAO_ENCONTRADO, com motivo indicando que a SEFAZ não tem o doc.
}
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoServiceTest`
Expected: FAIL (comportamento novo ainda não implementado).

- [ ] **Step 3: Implementar**

Em `porChave`, após o `137` atual: chamar `$this->manifestacao->ciencia($unidade, $chave)`, reconsultar `distDFePorChave`, e, se ainda `137`, executar varredura incremental com `$this->client->distDFe($unidade, $ultimoNsu)` lendo o `Nsu` local (sem salvar), até `maxNSU` ou `max_iteracoes`, processando cada doc via `DocumentoProcessor` e filtrando pela chave. Injetar `ManifestacaoService` no construtor.

- [ ] **Step 4: Rodar e ver passar**

Run: `docker compose exec -T app php artisan test --filter=RecuperacaoServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add backend/app/Domain/Sefaz/RecuperacaoService.php backend/app/Domain/Sefaz/ResultadoRecuperacao.php backend/tests/Feature/Sefaz/RecuperacaoServiceTest.php
git commit -m "feat: recuperação por chave envia Ciência e varre a fila quando 137"
```

---

### Task 4: Notificação — mensagens honestas por caminho

**Files:**
- Modify: `backend/app/Notifications/RecuperacaoConcluida.php`
- Test: `backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php`

**Interfaces:**
- Consumes: `ResultadoRecuperacao` (com `motivo`).

- [ ] **Step 1: Escrever o teste que falha**

```php
public function test_mensagem_137_com_ciencia_nao_sugere_erro(): void
{
    $n = new RecuperacaoConcluida('chave x', ResultadoRecuperacao::de(
        ResultadoRecuperacao::NAO_ENCONTRADO, 0, 'documento não consta na fila da SEFAZ'
    ));
    $msg = $n->toArray(null)['mensagem'];
    $this->assertStringContainsString('SEFAZ', $msg);
}
```

- [ ] **Step 2–4:** RED → implementar `mensagem()` para distinguir os casos → GREEN.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Notifications/RecuperacaoConcluida.php backend/tests/Feature/Nfe/RecuperacaoConcluidaTest.php
git commit -m "fix: mensagem de recuperação 137 não sugere mais 'aguarde a Ciência'"
```

---

### Task 5: Job — disparar sincronização normal da unidade

**Files:**
- Modify: `backend/app/Jobs/RecuperarNfeJob.php`
- Test: `backend/tests/Feature/Nfe/RecuperarNfeJobTest.php`

**Interfaces:**
- Consumes: `SyncNfeJob::dispatch(int $unidadeId)`.

- [ ] **Step 1: Escrever o teste que falha**

```php
public function test_recuperacao_dispara_sync_da_unidade(): void
{
    Queue::fake();
    // ... dispara RecuperarNfeJob->handle() ...
    Queue::assertPushed(SyncNfeJob::class, fn ($j) => $j->unidadeId === $unidade->id);
}
```

- [ ] **Step 2–4:** RED → implementar → GREEN.

- [ ] **Step 5: Commit**

```bash
git add backend/app/Jobs/RecuperarNfeJob.php backend/tests/Feature/Nfe/RecuperarNfeJobTest.php
git commit -m "feat: recuperação também enfileira sincronização da unidade"
```

---

### Task 6: Verificação end-to-end e limpeza

- [ ] **Step 1:** Suíte backend completa

Run: `docker compose exec -T app php artisan test`
Expected: todos verdes.

- [ ] **Step 2:** Pint

Run: `docker compose exec -T app ./vendor/bin/pint app/Domain/Sefaz app/Jobs app/Notifications`
Expected: sem alterações pendentes.

- [ ] **Step 3:** Horizon

Run: `docker compose restart horizon; docker compose exec -T horizon php artisan horizon:status`
Expected: `running`.

- [ ] **Step 4:** Frontend (se houver mudança de texto)

Run: `cd frontend && npm test -- --run && npm run build`
Expected: verde.

- [ ] **Step 5: Commit final**

```bash
git add -A
git commit -m "chore: verificação final da correção de recuperação manual"
```
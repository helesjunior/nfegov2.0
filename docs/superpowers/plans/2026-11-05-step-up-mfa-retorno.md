# Step-up de MFA com retorno e rascunho — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quando uma ação sensível falhar por falta de MFA recente (403 `mfa_step_up_required`), redirecionar para `/mfa`; após confirmar o código, voltar à tela de origem com o formulário reaberto e preenchido, e mostrar o aviso "Verificação renovada".

**Architecture:** Estado efêmero de desafio num módulo singleton fora do React (`features/auth/stepUp.ts`) com hook `useStepUp()` via `useSyncExternalStore`. As telas sensíveis (entalhes: entidade/unidade) detectam o 403 com `ehErroDeStepUp` (já existente), registram rota+rascunho e navegam para `/mfa`. A `MfaPage`, no sucesso do `verify`, volta pela rota registrada; a **página** de origem (não o form interno) reabre o formulário e o form nasce preenchido. O certificado mantém o fluxo inline existente.

**Tech Stack:** React 19 + TypeScript + Vite, react-router-dom 7, @tanstack/react-query 5, axios. Testes: vitest + @testing-library/react. Sem libs novas.

**Spec:** `docs/superpowers/specs/2026-11-05-step-up-mfa-retorno-design.md`

## Global Constraints

- Sem dependências novas (Zustand **não** está instalado e não será instalado).
- Reutilizar `ehErroDeStepUp(erro)` de `frontend/src/lib/api.ts:339` — não criar função nova.
- O flag do backend é `403` com corpo `{ mfa_step_up_required: true }`.
- Consumo do rascunho deve ocorrer em `useEffect` (não em `useState` initializer), por causa do `<StrictMode>` em `main.tsx:11`.
- Caminhos de teste do frontend seguem a convenção `*.test.tsx` ao lado do componente.
- Comandos rodam em `frontend/`: `npm run test`, `npm run build`, `npm run lint`.

## Review Focus

- **Ação sensível sem formulário (ativar/desativar):** deve redirecionar para `/mfa` e voltar; sem rascunho. Testar que o 403 dispara a navegação.
- **Abandonar `/mfa` sem confirmar:** o rascunho não pode reaparecer depois. Testar que sair de `/mfa` limpa tudo.
- **Voltar quando o formulário não está mais aberto:** a página precisa reabrir o form (`formAberto=true`). Testar que voltar com rascunho reabre e preenche.
- **StrictMode (dupla render):** o rascunho não pode sumir nem duplicar. Testar restauração com efeito, não no initializer.
- **Falha que NÃO é step-up:** não redirecionar; mostrar a mensagem normal. Testar que um 403 sem a flag (ou outro erro) mantém o comportamento atual.

---

### Task 1: Estado de desafio (`useStepUp`)

**Files:**
- Create: `frontend/src/features/auth/stepUp.ts`
- Test: `frontend/src/features/auth/stepUp.test.ts`

**Interfaces:**
- Produces:

```ts
export type OrigemRascunho = 'tenant' | 'unidade'
export type Rascunho = {
  origem: OrigemRascunho
  modo: 'criar' | 'editar'
  id?: number
  dados: Record<string, unknown>
}
export function useStepUp(): {
  desafiar(rota: string, rascunho?: Rascunho): void
  consumirRascunho(origem: OrigemRascunho): Rascunho | null
  usarRotaRetorno(): string | null
  limparRota(): void
  limparTudo(): void
  toggleRecémVerificado(v: boolean): void   // nome em snake? usar `marcarRecémVerificado(boolean): void`
}
```

Convenção: nomear a última ação `marcarRecémVerificado(vivo: boolean): void` e expor também `foiRenovado: boolean` no retorno do hook.

- [ ] **Step 1: Write the failing test**

```ts
// stepUp.test.ts
import { renderHook, act } from '@testing-library/react'
import { useStepUp } from './stepUp'

describe('useStepUp', () => {
  it('registra rota e rascunho, e consome o rascunho uma única vez', () => {
    const { result } = renderHook(() => useStepUp())
    act(() => result.current.desafiar('/entidades', { origem: 'tenant', modo: 'criar', dados: { nome: 'X' } }))
    expect(result.current.usarRotaRetorno()).toBe('/entidades')
    const r = result.current.consumirRascunho('tenant')
    expect(r?.dados).toEqual({ nome: 'X' })
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })

  it('limparTudo descarta rota e rascunho', () => {
    const { result } = renderHook(() => useStepUp())
    act(() => result.current.desafiar('/entidades', { origem: 'tenant', modo: 'criar', dados: {} }))
    act(() => result.current.limparTudo())
    expect(result.current.usarRotaRetorno()).toBeNull()
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })

  it('não devolve rascunho de outra origem', () => {
    const { result } = renderHook(() => useStepUp())
    act(() => result.current.desafiar('/unidades', { origem: 'unidade', modo: 'criar', dados: {} }))
    expect(result.current.consumirRascunho('tenant')).toBeNull()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- stepUp`
Expected: FAIL — módulo `./stepUp` não existe.

- [ ] **Step 3: Implement `stepUp.ts`**

Módulo singleton com estado `{ rota: string | null; rascunho: Rascunho | null; renovado: boolean }`, uma lista de subscribers, `useSyncExternalStore(subscribe, getSnapshot)`. Cada ação muta o estado e notifica. `consumirRascunho(origem)` devolve o rascunho se `rascunho.origem === origem` (e `modo`; devolve-o e o remove), senão `null`. `limparRota()` só zera `rota` e `renovado`. `limparTudo()` zera tudo. `marcarRecémVerificado(v)` ajusta `renovado`. Exporte o tipo `Rascunho`.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- stepUp`
Expected: PASS (3 testes).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/auth/stepUp.ts frontend/src/features/auth/stepUp.test.ts
git commit -m "feat(sessao): estado de desafio de step-up (rota + rascunho)"
```

---

### Task 2: Retorno e limpeza na `MfaPage`

**Files:**
- Modify: `frontend/src/features/auth/MfaPage.tsx`
- Test: `frontend/src/features/auth/MfaPage.test.tsx`

**Interfaces:**
- Consumes: `useStepUp()` (Task 1), `mfaApi.verify` (existente), `useSessao` (existente).
- Produces: comportamento — no sucesso do `verificar`, navega para `usarRotaRetorno()` se houver (senão `/`), chama `limparRota()` e `marcarRecémVerificado(true)`; ao desmontar sem sucesso, chama `limparTudo()`.

- [ ] **Step 1: Write the failing test**

```tsx
// MfaPage.test.tsx (mockar useNavigate, mfaApi, useSessao, useStepUp conforme já se faz no projeto)
it('após verify com rota de retorno, navega para a rota e mantém o rascunho', async () => {
  // desafio previamente registrado: rota '/entidades', rascunho origem 'tenant'
  // usuário com mfa_pendente=false
  // submeter código válido
  // expect(navigateMock).toHaveBeenCalledWith('/entidades')
  // expect(rascunho AINDA presente para consumo pelo form)
})

it('ao sair de /mfa sem confirmar, limpa tudo', () => {
  // renderizar, desmontar
  // expect(limparTudoMock).toHaveBeenCalled()
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- MfaPage`
Expected: FAIL — `MfaPage` não usa step-up.

- [ ] **Step 3: Modify `MfaPage.tsx`**

No `onSuccess` de `verificar`: ler `usarRotaRetorno()`; `marcarRecémVerificado(true)`; `limparRota()`; `navigate(rota ?? '/')`. Adicionar um `useEffect(() => () => { limparTudo() }, [])` **apenas quando não houve sucesso** — implementar com um `useRef` `concluido` marcado no sucesso; o cleanup só chama `limparTudo()` se `!concluido.current`.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- MfaPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/auth/MfaPage.tsx frontend/src/features/auth/MfaPage.test.tsx
git commit -m "feat(mfa): voltar para a tela de origem após renovar a verificação"
```

---

### Task 3: Entidade — redirecionar, reabrir e preencher

**Files:**
- Modify: `frontend/src/features/tenants/TenantsPage.tsx`
- Test: `frontend/src/features/tenants/TenantsPage.test.tsx`

**Interfaces:**
- Consumes: `useStepUp()`, `ehErroDeStepUp` (de `lib/api.ts`), `useNavigate`, `tenantsApi` (existente).
- Produces: `TenantForm` recebe `rascunhoInicial?: Rascunho` e semeia `nome`/`cnpj`/`ativo`; `TenantsPage` reabre o form ao montar se houver rascunho `origem:'tenant'` (via cache react-query para `modo:'editar'`).

- [ ] **Step 1: Write the failing test**

```tsx
it('403 de step-up: registra rascunho, redireciona para /mfa e não mostra erro genérico', async () => {
  // criar() rejeita com { response: { status: 403, data: { mfa_step_up_required: true } } }
  // preencher nome e enviar
  // expect(navigateMock).toHaveBeenCalledWith('/mfa')
  // expect(desafiarMock).toHaveBeenCalledWith('/entidades', expect.objectContaining({ origem: 'tenant', dados: expect.objectContaining({ nome }) }))
})

it('ao montar com rascunho de criação, reabre o formulário preenchido', async () => {
  // consumirRascunhoMock devolve { origem:'tenant', modo:'criar', dados:{ nome:'Nova', cnpj:'11222333000181' } }
  // renderizar
  // expect(screen.getByLabelText(/nome/i)).toHaveValue('Nova')
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- TenantsPage`
Expected: FAIL.

- [ ] **Step 3: Modify `TenantsPage.tsx`**

Em `TenantForm.salvar.onError`: se `ehErroDeStepUp(e)`, chamar `desafiar('/entidades', { origem:'tenant', modo: editando?'editar':'criar', id: tenant?.id, dados:{ nome, cnpj, ativo } })` e `navigate('/mfa')`; senão `setErro(mensagemDeErro(e))`. Em `TenantsPage`: `useEffect` que, ao montar, lê `consumirRascunho('tenant')`; se houver, define `formAberto=true`, `editando` (busca no cache `['tenants']` quando `modo:'editar'`, fallback `null`) e guarda o rascunho em estado para passar a `TenantForm`. A `TenantForm` recebe `rascunhoInicial` e inicializa os campos com ele. Mostrar banner "Verificação renovada" quando `foiRenovado`.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- TenantsPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/tenants/TenantsPage.tsx frontend/src/features/tenants/TenantsPage.test.tsx
git commit -m "feat(entidades): step-up redireciona para /mfa e restaura o formulário"
```

---

### Task 4: Unidade — redirecionar, reabrir e preencher

**Files:**
- Modify: `frontend/src/features/unidades/UnidadesPage.tsx`
- Test: `frontend/src/features/unidades/UnidadesPage.test.tsx`

**Interfaces:**
- Consumes: `useStepUp()`, `ehErroDeStepUp`, `useNavigate`, `unidadesApi`.
- Produces: mesmo padrão da Task 3, com `origem:'unidade'`, rota `/unidades`, dados `{ nome, nome_resumido, cnpj, uf, tp_amb }`.

- [ ] **Step 1: Write the failing test**

```tsx
it('403 de step-up ao criar unidade: registra rascunho e redireciona para /mfa', async () => {
  // unidadesApi.criar rejeita com 403 mfa_step_up_required
  // expect(navigateMock).toHaveBeenCalledWith('/mfa')
  // expect(desafiarMock).toHaveBeenCalledWith('/unidades', expect.objectContaining({ origem: 'unidade' }))
})

it('ao montar com rascunho de unidade, reabre o formulário preenchido', async () => {
  // consumirRascunho('unidade') devolve dados preenchidos
  // expect(screen.getByLabelText(/uf/i)).toHaveValue('DF')
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- UnidadesPage`
Expected: FAIL.

- [ ] **Step 3: Modify `UnidadesPage.tsx`**

Mesmo padrão da Task 3, com `origem:'unidade'` e `tp_amb`. `NovaUnidadeForm` recebe `rascunhoInicial` e semeia os campos.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- UnidadesPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/unidades/UnidadesPage.tsx frontend/src/features/unidades/UnidadesPage.test.tsx
git commit -m "feat(unidades): step-up redireciona para /mfa e restaura o formulário"
```

---

### Task 5: Ativar/desativar (entidade e unidade) com step-up

**Files:**
- Modify: `frontend/src/features/tenants/TenantsPage.tsx` (`BotaoAtivo`)
- Modify: `frontend/src/features/unidades/UnidadesPage.tsx` (`BotaoAtivo`)
- Test: ampliar `TenantsPage.test.tsx` e `UnidadesPage.test.tsx`

**Interfaces:**
- Consumes: `useStepUp()`, `ehErroDeStepUp`, `useNavigate`.
- Produces: no `onError` de `alternar`, se `ehErroDeStepUp(e)` → `desafiar('/entidades'|'/unidades')` (sem rascunho) + `navigate('/mfa')`; senão `setErro(...)`.

- [ ] **Step 1: Write the failing test**

```tsx
it('ativar/desativar sem step-up redireciona para /mfa (sem rascunho)', async () => {
  // window.confirm -> true; definirAtivo rejeita com 403 mfa_step_up_required
  // expect(navigateMock).toHaveBeenCalledWith('/mfa')
  // expect(desafiarMock).toHaveBeenCalledWith('/entidades')
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- TenantsPage UnidadesPage`
Expected: FAIL.

- [ ] **Step 3: Modify os dois `BotaoAtivo`**

Adicionar o tratamento de step-up conforme Interfaces (sem rascunho).

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- TenantsPage UnidadesPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/tenants/TenantsPage.tsx frontend/src/features/unidades/UnidadesPage.tsx frontend/src/features/tenants/TenantsPage.test.tsx frontend/src/features/unidades/UnidadesPage.test.tsx
git commit -m "feat(step-up): ativar/desativar redireciona para /mfa e volta"
```

---

### Task 6: Atalho "Renovar verificação" na Minha conta

**Files:**
- Modify: `frontend/src/features/perfil/MinhaContaPage.tsx`
- Test: `frontend/src/features/perfil/MinhaContaPage.test.tsx`

**Interfaces:**
- Consumes: `useStepUp()`, `useNavigate`.
- Produces: botão na seção Segurança que chama `desafiar('/minha-conta')` e `navigate('/mfa')`.

- [ ] **Step 1: Write the failing test**

```tsx
it('botão "Renovar verificação" define retorno para /minha-conta e navega para /mfa', async () => {
  // clicar no botão
  // expect(desafiarMock).toHaveBeenCalledWith('/minha-conta')
  // expect(navigateMock).toHaveBeenCalledWith('/mfa')
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npm run test -- MinhaContaPage`
Expected: FAIL.

- [ ] **Step 3: Modify `MinhaContaPage.tsx`**

Adicionar o botão na seção "Segurança (MFA)" — exibido quando `usuario.mfa_confirmado` (não pendente) — com o comportamento de Interfaces.

- [ ] **Step 4: Run test to verify it passes**

Run: `npm run test -- MinhaContaPage`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/features/perfil/MinhaContaPage.tsx frontend/src/features/perfil/MinhaContaPage.test.tsx
git commit -m "feat(perfil): atalho para renovar a verificação de segurança (MFA)"
```

---

### Task 7: Verificação final (suíte completa + build + navegador)

**Files:** nenhum (verificação).

- [ ] **Step 1: Suíte completa do frontend**

Run (em `frontend/`): `npm run test`
Expected: todos passam (incluindo os novos).

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: build OK, sem erros de TypeScript.

- [ ] **Step 3: Lint dos arquivos tocados**

Run: `npm run lint`
Expected: sem novos erros nos arquivos alterados (erros pré-existentes em `MinhaContaPage.tsx` de `rules-of-hooks` são anteriores e não devem piorar).

- [ ] **Step 4: Verificação no navegador**

Logar como `superadmin@agu.gov.br`/`senha123`, tentar criar entidade sem step-up → confirmar redirecionamento para `/mfa`, confirmar código TOTP, voltar com formulário preenchido e aviso.

- [ ] **Step 5: Commit final (se houver ajustes) e preparar push**

```bash
git status
# avisar o usuário antes do push (regra combinada)
```
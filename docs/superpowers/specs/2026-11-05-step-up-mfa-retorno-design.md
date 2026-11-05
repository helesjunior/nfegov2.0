# Design — Step-up de MFA com retorno e rascunho de formulário

Data: 2026-11-05

## Contexto e motivação

Operações sensíveis (criar/editar/ativar entidade, editar unidade, enviar
certificado) exigem **MFA recente** (middleware `mfa.recent`, janela padrão de
15 min). Na prática, o super-admin é bloqueado com `403` +
`mfa_step_up_required` e **não há caminho na interface** para renovar a janela:

- a tela `/mfa` existe e sabe renovar (`POST /api/mfa/verify`), mas é
  **inalcançável** por quem já tem o MFA confirmado (o `RotaProtegida` só
  redireciona para lá quando `mfa_pendente` — `router.tsx:38`);
- o login **não** marca `mfa_recent_at` (correto: o login prova a senha, não o
  TOTP) — confirmado no banco: `superadmin` tem `mfa_recent_at = null`;
- nenhum erro `403` de step-up é tratado nas telas de entidade/unidade; vira
  apenas a mensagem de erro.

Resultado: o usuário fica travado — relogar não resolve, porque o login não
marca a janela recente.

## Objetivo

Quando uma ação sensível falhar por falta de step-up, redirecionar o usuário
para `/mfa`; após confirmar o código, **voltar para a tela de origem com o
formulário reaberto e preenchido** (rascunho preservado), com um aviso de que a
verificação foi renovada. O usuário reenvia a ação manualmente.

## Escopo

**Dentro:**
- Tratar `403` com `mfa_step_up_required: true` nas telas de entidade e unidade.
- Redirecionar para `/mfa` guardando (a) a rota de retorno e (b) o rascunho do
  formulário que originou a ação (quando houver).
- Após `verify` bem-sucedido, voltar, **reabrir o formulário**, restaurar o
  rascunho e mostrar o aviso "Verificação renovada".
- Formulários/ações abrangidos: **criar/editar entidade** (`TenantsPage` →
  `TenantForm`), **criar/editar unidade** (`UnidadesPage` → `NovaUnidadeForm`),
  **ativar/desativar** entidade e unidade (sem rascunho — não há o que
  preencher; apenas redireciona, volta e o usuário reclicа).
- Atalho explícito "Renovar verificação de segurança" na Minha conta → `/mfa`
  (com retorno para `/minha-conta`).

**Fora:**
- **Certificado** (upload/rotação): mantém o fluxo **inline já existente** em
  `CertificadoPage.tsx:127-159` (detecta step-up, pede o código no próprio
  diálogo e **reenvia automaticamente**). Decisão do produto: não migrar.
- Alterar o middleware `/mfa/verify` (já funciona).
- Marcar `mfa_recent_at` no login (enfraqueceria a segurança).

## Abordagem escolhida

**Estado efêmero de desafio num módulo singleton (sem lib nova) + reabertura do
formulário no nível da página.**

### Componentes

1. **Detecção do 403** — reutilizar `ehErroDeStepUp(erro)`, **já existente** em
   `lib/api.ts:339` (com teste em `api.test.ts`). Não criar função nova.

2. **Estado de desafio (`features/auth/stepUp.ts`)** — módulo singleton simples
   (fora do React) com subscribe, expondo um hook `useStepUp()`:
   - `rotaRetorno: string | null`
   - `rascunho: Rascunho | null`, onde
     `Rascunho = { origem: 'tenant' | 'unidade'; modo: 'criar' | 'editar'; id?: number; dados: Record<string, unknown> }`
   - ações **separadas**: `desafiar(rota, rascunho?)`, `limparRota()`,
     `consumirRascunho(origem)` (remove e devolve), `limparTudo()`.
   - Sem Zustand (não está instalado). Um singleton com `useSyncExternalStore`
     é suficiente e testável.

3. **Redirecionar para `/mfa`** — feito no `onError` de cada mutation sensível:
   quando `ehErroDeStepUp(e)`, registrar o desafio (rota + rascunho) e
   `navigate('/mfa')`. Não usar interceptor axios (não tem acesso ao router).

4. **Retorno pós-verify (`MfaPage.tsx`)** — no sucesso do `verify`, ler
   `rotaRetorno`; se houver, `navigate(rotaRetorno)` + definir um flag "recém
   verificado" (para o aviso); senão, manter o atual (`navigate('/')`). Chamar
   `limparRota()` (não `limparTudo()`) — o rascunho é consumido pelo formulário.
   Se o usuário **sair de `/mfa` sem confirmar** (desmontar a página ou navegar
   para outra rota sem sucesso), chamar `limparTudo()` — descarta rascunho e
   retorno (decisão do produto).

5. **Reabertura + restauração do formulário (nível da PÁGINA)** — este é o
   ponto que precisa de cuidado: `formAberto`/`editando` são estado local de
   `TenantsPage`/`UnidadesPage`, que **desmontam** ao navegar para `/mfa`. Ao
   voltar, é a **página** (não o form) que lê o rascunho e define
   `formAberto=true` + `editando`, e o form nasce (via `key`) já com os dados.
   - O rascunho carrega `modo` e `id` para reconstruir `editando`. Para o caso
     `editar`, a página obtém a entidade/unidade do cache do react-query
     (`queryClient.getQueryData(['tenants'|'unidades'])`); se não estiver
     disponível, cai para `criar` (o rascunho só restaura os campos).
   - O rascunho é lido **sem efeito colateral** num `useState` lazy initializer
     e **removido num `useEffect`** — evita o problema do `StrictMode`
     (`main.tsx:11`), que executa initializers duas vezes em dev.

6. **Aviso "Verificação renovada"** — um banner informativo nas telas de origem,
   alimentado pelo flag "recém verificado" do passo 4, orientando a clicar em
   Salvar para concluir.

### Alternativas descartadas

- **Reenvio automático da requisição (C):** melhor fluidez, mas exige replay de
  requisições (idempotência, multipart). Para certificado, o projeto já usa o
  inline; para os demais, rejeitada por complexidade/risco.
- **Só voltar sem rascunho (A):** o usuário redigita tudo.
- **Zustand:** não instalado; desnecessário para estado efêmero.
- **Redirecionar do interceptor axios:** sem acesso ao router React.

## Decisões de produto (confirmadas)

- **Certificado:** mantém o fluxo inline existente (com reenvio automático).
- **Ativar/desativar:** também redireciona para `/mfa` e volta (sem rascunho).
- **Abandonar `/mfa` sem confirmar:** descarta rascunho e retorno.
- **Feedback:** aviso "Verificação renovada" ao voltar + formulário preenchido.

## O que fica a cargo do implementador

- Nomes de arquivos/testes conforme convenções existentes.
- Estilo visual do banner (usar `alerta alerta--info`) e do botão da Minha conta
  (`botao botao--secundario`).
- Detalhes do singleton/hook, desde que testável e sem lib nova.

## Riscos

- **Rascunho obsoleto:** mitigado por `limparTudo()` ao abandonar `/mfa` e pelo
  consumo no retorno.
- **Origem ambígua (criar vs. editar):** rascunho carrega `modo`/`id`; só aplica
  quando o contexto casa; fallback para `criar` se a entidade não estiver no
  cache.
- **StrictMode:** consumir o rascunho em `useEffect`, não no initializer.
- **Página vs. form:** a restauração vive na **página** (que controla
  `formAberto`/`editando`), não no form interno.

## Testes previstos (TDD)

- `ehErroDeStepUp` (já testado) — reutilizado.
- Hook/estado de desafio: `desafiar`, `consumirRascunho` (remove), `limparTudo`.
- `TenantForm`/`NovaUnidadeForm`: ao faltar step-up, redireciona e registra
  rascunho; ao voltar com rascunho, o form nasce preenchido.
- `MfaPage`: verify com `rotaRetorno` navega para ela e não limpa o rascunho;
  sair sem confirmar limpa tudo.
- Botão "Renovar verificação" na Minha conta navega para `/mfa` com retorno.
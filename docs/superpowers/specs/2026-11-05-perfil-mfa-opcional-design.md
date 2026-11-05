# Área de perfil do usuário e MFA opcional self-service — Design

**Data:** 2026-11-05
**Status:** aguardando aprovação do parceiro humano
**Escopo:** backend (Laravel 13) + SPA (React)
**Abordagem escolhida:** A — rotas dedicadas de "self" (`/api/me/...`)

## Intenção

Hoje o usuário não tem onde editar os próprios dados: o `/api/usuarios` é
**administrativo** (exige `usuario.gerenciar` e policy de gestão) e ainda
bloqueia a auto-edição de campos sensíveis. Além disso, o MFA (TOTP) já existe,
mas é **reativo**: só aparece quando o backend exige (papéis administrativos) ou
para step-up. Não há como um usuário **escolher** habilitar/desabilitar o
segundo fator.

Este trabalho entrega uma tela **"Minha conta"** onde qualquer usuário logado:

1. atualiza **nome** e **e-mail**;
2. troca a **senha**;
3. habilita/desabilita o **MFA**, quando não for obrigado pelo papel.

## Por que (motivação do parceiro)

- "Preciso que crie uma área para o usuário atualizar seu nome, email e senha.
  Não tem área do usuário para atualizar seus próprios dados."
- "Seria importante também que deixasse a opção OTP (MFA) para que ele habilite
  ou não, se achar pertinente."

## Decisões tomadas (com o parceiro)

1. **Troca de senha exige a senha atual** (+ nova senha com confirmação).
   Protege contra uso de uma sessão aberta por terceiro.
2. **Troca de e-mail exige a senha atual** (e-mail é credencial de login).
   Mudar só o **nome** não pede senha.
3. **Desabilitar o MFA exige um código TOTP válido** — prova de posse do
   autenticador, evita que uma sessão sequestrada remova o 2º fator.
4. **MFA opcional APENAS para quem não é obrigado.** `super-admin` e
   `admin-tenant` continuam **obrigatórios** e **não podem desligar**;
   `operador`/`consulta` ligam/desligam livremente.
5. **Tela única com seções** (Dados pessoais / Senha / Segurança).
6. **Quem é obrigado a MFA vê só o status** (sem botão de desligar). Se ainda
   não confirmou, vê o fluxo de ativar (QR + código).
7. **Após trocar a senha, o usuário continua logado** na sessão atual.
8. **Acesso pelo menu do usuário no topo** (topbar), abrindo "Minha conta".

## Não-objetivos

- Alterar a regra de MFA obrigatório por papel (config
  `nfegov.mfa.papeis_obrigatorios` permanece).
- Permitir que o usuário edite **papel**, **vínculos de tenant** ou **status
  ativo** (isso continua sendo gestão administrativa).
- Recuperação de senha por e-mail ("esqueci minha senha") — não faz parte deste
  escopo.
- Encerrar outras sessões/dispositivos ao trocar a senha.
- Confirmação de e-mail por token (a troca é imediata, mediante senha atual).

## Estado atual relevante (verificado no código)

- `AuthController::dadosUsuario()` (usado por `/login` e `/me`) monta o usuário
  para a SPA: já expõe `mfa_pendente` e `mfa_confirmado`; calcula `exigeMfa` via
  `config('nfegov.mfa.papeis_obrigatorios')`. **Ponto natural** para expor
  também `mfa_obrigatorio`.
- `MfaController`: `setup` (gera segredo, devolve URL otpauth), `confirm`
  (valida código e confirma), `verify` (renova janela de step-up). **Falta** o
  caminho de **desabilitar**.
- `TwoFactorService`: `gerarSegredo`, `verify`/`verifySecret`, `qrUrl`,
  `marcarRecente`, `recente`, `confirmado`. Toda a lógica TOTP está aqui.
- `RequireMfa` (middleware `mfa`): bloqueia papéis obrigatórios sem confirmação.
  As rotas `mfa/*` estão **fora** dele justamente para permitir a configuração.
- `routes/api.php`: grupo de sessão `['auth:sanctum','tenant']` (onde ficam
  `/me`, `/sessao/tenant`, `/mfa/*`) — **sem** o middleware `mfa`. É o grupo
  correto para as rotas de perfil (inclusive para quem ainda não confirmou o
  MFA, que precisa acessar a área para configurá-lo).
- `backend/config/nfegov.php` → `mfa.papeis_obrigatorios = ['super-admin','admin-tenant']`.
- `UserController` (administrativo): referência de validação/policy; **não** é
  reaproveitado aqui (ver Abordagem descartada).
- SPA: `frontend/src/features/auth/session.tsx` (`SessionProvider`/`useSessao`,
  `recarregar` → `invalidateQueries(['sessao'])`); `features/auth/MfaPage.tsx`
  (tela atual de MFA, sem desabilitar); `lib/api.ts` (tipos `Usuario`,
  `SetupMfa` e apis `authApi`/`mfaApi`/`sessaoApi`); `components/layout/Topbar.tsx`
  (barra superior com nome do usuário + botão "Sair").

## Arquitetura (Abordagem A — rotas dedicadas de self)

### 0. Por que rotas dedicadas e não o CRUD administrativo

O CRUD `/api/usuarios/{id}` é protegido por autorização de **gestão**
(`usuario.gerenciar` + `UserPolicy`) e **bloqueia** a auto-edição de campos
sensíveis. Um `operador`/`consulta` não tem essa permissão e não conseguiria
editar o próprio perfil por ali. Além disso, as regras de autoatendimento
(senha atual, senha pessoal, MFA) diferem das regras do admin. Por isso, rotas
próprias, com `Requests` próprios, **sem** depender de policy de gestão.

### 1. Backend — rotas

Adicionadas ao grupo de sessão `['auth:sanctum','tenant']` em `routes/api.php`
(mesmo grupo de `/me` e `/mfa/*`, portanto **sem** o middleware `mfa` — quem
ainda não confirmou o MFA precisa acessar a área para configurá-lo):

| Método | Rota | Ação | Regra |
|---|---|---|---|
| `PUT` | `/api/me` | Atualiza nome e e-mail | E-mail: único, formato válido. Se o e-mail **mudou**, exige `current_password`. |
| `PUT` | `/api/me/password` | Troca a senha | Exige `current_password` + `password` (confirmada, regras padrão). |
| `DELETE` | `/api/me/mfa` | Desabilita o MFA | Exige `code` (TOTP válido) e que o papel **não** seja obrigatório. |

Ativar o MFA **reutiliza** `POST /api/mfa/setup` e `POST /api/mfa/confirm` já
existentes (nenhuma rota nova para habilitar).

Todas respondem com o **usuário atualizado** (mesmo formato de `/me`), para a
SPA atualizar a sessão numa única chamada.

### 2. Backend — controller e requests

- **`ProfileController`** (novo, `app/Http/Controllers/Api/ProfileController.php`):
  - `update(UpdateProfileRequest)`: atualiza `name`/`email`; se o e-mail mudou,
    `Auth::guard('web')` já é o usuário — apenas persiste. Retorna usuário.
  - `updatePassword(UpdatePasswordRequest)`: `Hash::make($password)` + save.
    Mantém a sessão atual (nada de logout).
  - `destroyMfa(Request)`: valida `code` (TOTP) e o papel; limpa
    `two_factor_secret` e `two_factor_confirmed_at`; retorna usuário.
- **`UpdateProfileRequest`**: `name` required/string/max; `email`
  required/email/unique ignorando o próprio id; `current_password` **required
  quando `email` difere do atual** (`Rule::requiredIf(...)` + `current_password`
  rule), `sometimes`. A regra usa o hash do próprio usuário autenticado.
- **`UpdatePasswordRequest`**: `current_password` required + regra
  `current_password`; `password` required + `confirmed` + `Password::defaults()`.
- **`DisableMfaRequest`**: `code` required/string. A checagem de "papel
  obrigatório" (403 se obrigatório) e a validação do TOTP ocorrem no controller
  (mensagens de erro específicas).

Todas as respostas usam o mesmo `dadosUsuario()` de `/me`. Para não duplicar,
extraímos esse método para um **`UserPresenter`** (serviço) reutilizado por
`AuthController` e `ProfileController`. (Alternativa mais simples: manter o
método em `AuthController` e expô-lo via um trait/serviço; a extração evita a
dependência circular e é a escolha do design.)

### 3. Backend — `/me` ganha a flag `mfa_obrigatorio`

`dadosUsuario()` passa a expor:

- `mfa_obrigatorio` (bool): o papel do usuário exige MFA.
- `mfa_pendente` (bool, já existe): obrigatório e ainda não confirmado.
- `mfa_confirmado` (bool, já existe): TOTP confirmado.

Com isso a SPA decide mostrar/esconder o botão de desligar **sem** inferir regra
de negócio a partir de `papeis`.

### 4. Backend — desabilitar MFA (regras)

`ProfileController::destroyMfa`:

1. Se `hasAnyRole(config('nfegov.mfa.papeis_obrigatorios'))` → **403** com
   mensagem clara ("Seu perfil exige MFA; não é possível desabilitar.").
2. Se o MFA não está confirmado → **422** (nada a desabilitar).
3. Se o `code` não valida pelo `TwoFactorService::verify` → **422** com erro no
   campo `code`.
4. Caso válido: `two_factor_secret = null`, `two_factor_confirmed_at = null`,
   `mfa_recent_at = null`. Retorna o usuário atualizado.

### 5. Frontend — tela "Minha conta"

- **Rota** `/minha-conta` (nova feature `frontend/src/features/perfil/`).
- **Acesso:** no `Topbar`, o nome do usuário vira um **menu/botão** que abre a
  rota "Minha conta" (e mantém o "Sair"). Sem item na sidebar de negócio.
- **Seções:**
  1. **Dados pessoais** — form nome + e-mail. Se o e-mail for alterado, um campo
     "Senha atual" aparece (obrigatório). Sucesso → toast/aviso e sessão
     recarregada (`invalidateQueries(['sessao'])`).
  2. **Senha** — senha atual + nova + confirmação. Sucesso → aviso; continua
     logado.
  3. **Segurança (MFA)** — estado atual:
     - Não configurado e **não** obrigatório: botão "Ativar MFA" → QR +
       `setup`/`confirm` (reusa o fluxo da `MfaPage`).
     - Configurado e **não** obrigatório: mostra "Ativo" + botão "Desabilitar"
       (pede código TOTP).
     - **Obrigatório**: mostra "Ativo (exigido pelo seu perfil)" e, se não
       confirmado, o fluxo de ativar. **Sem** botão de desligar.
- **API client (`lib/api.ts`):** novas funções `perfilApi.atualizar(dados)`,
  `perfilApi.trocarSenha(dados)`, `perfilApi.desabilitarMfa(code)`; tipo
  `Usuario` ganha `mfa_obrigatorio`.

### 6. Segurança / consistência

- Todas as rotas de perfil exigem `auth:sanctum` (sessão). Reincluímos apenas o
  `tenant` por consistência do grupo? Não: as rotas de perfil **não** dependem de
  tenant. Ficam no grupo de sessão existente (que já tem `tenant`), sem prejuízo.
- A troca de e-mail **não** regenera a sessão nem invalida o login (a sessão é
  por id de usuário, não por e-mail).
- O segredo TOTP nunca é devolvido após confirmado (`User` mantém em `$hidden`).
- Auditoria: as mutações passam pelo `AuditLogger` já existente onde aplicável
  (seguir o padrão dos demais controllers; avaliar no plano).

## Alternativas consideradas

- **B — Estender o `AuthController`:** menos arquivos, mas mistura "auth" com
  "perfil" e engorda o controller. Descartada em favor de A.
- **C — Reusar `/api/usuarios/{id}`:** inviável — autorização de gestão e
  bloqueio de auto-edição impedem `operador`/`consulta`; regras divergentes.
  Descartada.

## Riscos e mitigação

- **Escalada por sessão aberta:** mitigada exigindo senha atual (e-mail/senha) e
  código TOTP (desabilitar MFA).
- **Vazamento de regra de MFA para a SPA:** evitado expondo `mfa_obrigatorio` no
  `/me`, em vez de a SPA reimplementar a lista de papéis.
- **Duplicação do `dadosUsuario`:** resolvida extraindo um `UserPresenter`.
- **Rota de perfil sob o grupo `mfa`:** não colocar — impediria quem ainda não
  confirmou de acessar a própria área para configurar o MFA.

## Testes (a escrever no plano)

- **Backend (Feature):**
  - Atualiza nome sem senha; atualiza e-mail **com** senha; e-mail duplicado
    falha; e-mail igual ao atual não exige senha.
  - Troca de senha: senha atual errada → 422; correta → 200 e novo login com a
    nova senha funciona; continua autenticado.
  - Desabilitar MFA: papel obrigatório → 403; código inválido → 422; código
    válido → 200 e `mfa_confirmado` vira falso; `operador` desabilita com
    sucesso.
  - `/me` inclui `mfa_obrigatorio` conforme o papel.
- **Frontend (Vitest/RTL):**
  - Exibe campo "senha atual" apenas quando o e-mail muda.
  - Mostra botão "Desabilitar" só quando não obrigatório; oculto quando
    obrigatório.
  - Fluxo de desabilitar envia o código e recarrega a sessão.

## Plano de implementação

A ser detalhado pelo skill `writing-plans` **após a aprovação deste design**.
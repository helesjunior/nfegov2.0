# CRUDs administrativos, multitenancy de usuário e seletor de entidade — Design

**Data:** 2026-11-04
**Status:** aguardando aprovação do parceiro humano
**Escopo:** backend (Laravel 13) + SPA (React)

## Intenção

O sistema hoje só cria Tenant (seed), Unidade (criar) e não tem CRUD de Usuário. Um erro de cadastro não tem correção pela interface. Além disso, o modelo é 1 usuário → 1 tenant, impedindo que uma pessoa administre várias entidades (ex.: um procurador que responde por várias prefeituras).

Este trabalho:
1. cria o CRUD de **Tenants** (super-admin);
2. cria o CRUD de **Usuários** (super-admin: qualquer tenant; admin-tenant: o próprio), com **papel global** e **vínculo a um ou mais tenants**;
3. adiciona **editar/desativar** de **Unidades**;
4. introduz o **seletor de entidade** na sessão, do qual o super-admin escolhe "Todas" ou uma entidade, e os demais escolhem entre as suas.

## Por que (motivação do parceiro)

- "Senti falta das seguintes CRUDs" (usuário, tenant); "se for cadastrado errado? como corrigir?" (edição de unidade).
- "O super admin pode trocar para qualquer tenant... um seletor do tenant da seção."
- "Um admin tenant pode ter permissão em mais de um tenant, ou seja, o usuário pode ser multitenant."

## Decisões tomadas (com o parceiro)

1. **Papel é GLOBAL por usuário** (não varia por tenant). Spatie permanece **sem** "teams".
2. **Super-admin tem modo "Todas as entidades" + entidades específicas.**
3. **Cadastro de usuário:** nome/e-mail/senha + papel (global) + **um ou mais** vínculos de tenant. Super-admin vincula a qualquer tenant; admin-tenant só ao próprio.
4. **Tenant ativo vive na SESSÃO** (abordagem A), validado contra os vínculos.
5. **Desativar tem enforcement real:** entidade desativada não é selecionável nem logável e suas unidades não sincronizam; usuário desativado não loga (sessão cai na próxima requisição).
6. **Unidade NÃO muda de entidade** na edição (campo fixo). Evita migrar certificado/NSU/NF-e.
7. **A lista de usuários respeita o seletor:** em "Todas" mostra todos; dentro de uma entidade mostra só os vinculados (via `tenant_user`, não pela coluna legada).

## Não-objetivos

- Papel por tenant / ligar "teams" do Spatie.
- Permissões finas por tenant (ex.: permissão X só no tenant B).
- Remover a coluna `users.tenant_id` neste momento (fica por compatibilidade).
- Emissão de NF-e (o sistema é só recepção/consulta).

## Estado atual relevante (verificado no código)

- `users.tenant_id` (nullable, FK → tenants). Fonte **única** do tenant.
- `SetTenantContext` (middleware `tenant`): `TenantContext::set($user?->tenant_id)`.
- `TenantContext`: estático; `set/id/clear/runAs`. Alimenta o global scope `BelongsToTenant`.
- `BelongsToTenant`: filtra por `tenant_id` **e** preenche no `creating` a partir do contexto.
- `UnidadePolicy`: já tem `update`/`delete` (exigem `unidade.gerenciar`); faltam rota/controller.
- `RolesSeeder`: permissões `usuario.gerenciar` (sem uso), `unidade.gerenciar`; papéis `super-admin`, `admin-tenant`, `operador`, `consulta`.
- Sanctum: `statefulApi()` — API autentica por cookie de sessão. Sessão disponível no middleware.
- `AuditLogger:39`: `$tenantId = $user?->tenant_id ?? TenantContext::id();` ← **atenção**: audita com o tenant do usuário, não o da sessão (ver Riscos).

## Arquitetura

### 0. Regra central: o que filtra por tenant

Decisão (resolve a tensão entre a coluna legada `users.tenant_id` e a pivô `tenant_user`):

- **`tenant_user` é a única fonte de verdade dos vínculos.** `users.tenant_id` fica apenas como "tenant de origem" e **NÃO** deve ser usado para filtrar/autorizar em nenhum lugar novo.
- O model `User` **mantém** o global scope (`BelongsToTenant`), mas **as consultas administrativas de usuários usam `withoutGlobalScopes()` + filtro explícito por `whereHas('tenants')`**. `UserController` e `UserPolicy` não confiam no scope para decidir vínculo.
- Ao **criar** um usuário, o `creating` do trait ainda carimba `users.tenant_id` (compatibilidade): definimos `tenant_id` = **primeiro tenant do vínculo** (ou null, caso só de super-admin). O vínculo real vai para `tenant_user` em transação.

### 1. Vínculo N:N — tabela `tenant_user`

- `tenant_user`: `id`, `tenant_id` (FK), `user_id` (FK), timestamps, `unique(tenant_id, user_id)`.
- Model `User`: `tenants()` (belongsToMany). Mantém `tenant()` (belongsTo da coluna legada).
- **Migração**: copia `users.tenant_id` → `tenant_user` para todos os usuários com tenant. **Super-admins** (`tenant_id` nulo) **não** ganham vínculo — usam "Todas"/seletor.
- **Toda mutação de vínculo escreve em `tenant_user`** (fonte de verdade); a coluna legada não é sincronizada depois disso.

### 2. Tenant ativo na sessão (abordagem A)

Resolução **num único lugar** (serviço `TenantAtivoResolver`, usado por middleware e `/me` — evita divergência entre o que a tela mostra e o que é filtrado):

1. super-admin: `session('tenant_id')` (pode ser null = "Todas"); se apontar para tenant inexistente/inativo → trata como "Todas".
2. não-super-admin: `session('tenant_id')` **se** houver vínculo com um tenant **ativo**; senão, o **primeiro vínculo** (menor `tenant_id`) entre os ativos; senão → **403** (não-super-admin sem vínculo nunca recebe contexto nulo — ver Riscos #3).
3. A sessão **não** precisa conter `tenant_id` para funcionar: o resolver calcula o efetivo. `POST /sessao/tenant` apenas **grava a preferência**.

- Novo endpoint `POST /api/sessao/tenant` (body `{ tenant_id: number | null }`).
  - Middleware: `auth:sanctum` + `tenant` (sem chicken-and-egg: o próprio `tenant` usa o resolver; o switch só muda a preferência).
  - Valida: super-admin → qualquer tenant **ativo**; senão → precisa ter vínculo com tenant ativo. Grava `session('tenant_id')`.
- `GET /api/me` continua devolvendo `tenant_id` (coluna legada — contrato atual preservado) **e** adiciona: `tenant_ativo` ({id, nome} | null), `tenants_disponiveis` ([{id, nome}]), `pode_todas` (bool: é super-admin).
- Seleção é **sempre persistida** na sessão (inclusive quando há 1 só vínculo); o resolver é idempotente.
- `TenantContext` **não muda** — muda só quem o alimenta.

### 3. CRUD de Tenants (novo `TenantController`, só super-admin)

- Rotas sob `auth:sanctum` + `mfa` (não usam `tenant`: `Tenant` não tem scope).
- `GET /api/tenants` — lista (todos).
- `POST /api/tenants` — criar (nome, cnpj opcional, ativo).
- `GET /api/tenants/{id}` — detalhe.
- `PUT /api/tenants/{id}` — editar.
- `PUT /api/tenants/{id}/ativo` — ativar/desativar (**enforcement**: ver §8).
- Sem DELETE físico (`SoftDeletes` já existe). Policy `TenantPolicy`: exige `super-admin`. Criar/editar/desativar exigem **`mfa.recent`** (operação de plataforma).

### 4. CRUD de Usuários (novo `UserController`)

- Rotas sob `auth:sanctum` + `tenant` + `mfa`.
- `GET /api/usuarios` — respeita o seletor (§7): em "Todas" (super-admin), todos; dentro de tenant, `whereHas('tenants', ...)`. Admin-tenant: só usuários com vínculo ao(s) tenant(s) dele.
- `POST /api/usuarios` — criar (nome, email, password, papel, `tenants[]`).
  - Super-admin: `tenants[]` livre. Admin-tenant: só o(s) tenant(s) dele.
- `PUT /api/usuarios/{id}` — editar (nome, email, papel, `tenants[]`; senha opcional).
- `PUT /api/usuarios/{id}/ativo` — ativar/desativar (sem DELETE físico).
- Regras: e-mail único **global**; admin-tenant **não** cria/altera `super-admin`; admin-tenant **não** altera a si próprio em campos sensíveis (papel, vínculo do tenant atual, ativo) — evita auto-lockout.
- `UserPolicy` (nova): `viewAny`/`create`/`update` exigem `usuario.gerenciar` (ou super-admin). **Escopo N:N próprio** da policy (consulta `tenant_user` via `whereHas`), **sem** usar `PertenceAoTenant::mesmoTenant()` (que compara a coluna legada e não expressa N:N). `PertenceAoTenant` **não** é alterado (continua servindo Unidade/Certificado).
- Auditoria: `usuario.criado`, `usuario.atualizado`, `usuario.vinculo.alterado`, `usuario.ativo.alterado`.

### 5. Unidades: editar e desativar (completar `UnidadeController`)

- Rotas sob `auth:sanctum` + `tenant` + `mfa`.
- `GET /api/unidades/{unidade}` — detalhe (404 fora do tenant).
- `PUT /api/unidades/{unidade}` — editar (mesmas regras do `StoreUnidadeRequest`, `Rule::unique` ignorando o próprio id). **`tenant_id` NÃO é editável** (mover proibido); se vier no request, é ignorado/rejeitado.
- `PUT /api/unidades/{unidade}/ativo` — ativar/desativar.
- Auditoria: `unidade.atualizada`, `unidade.ativo.alterado`. Policy já pronta (`update`/`delete`).

### 6. Criação de recurso pelo super-admin (corrige o vínculo implícito)

- Com o seletor, o super-admin cria unidade **dentro** do tenant da sessão; o `BelongsToTenant` preenche `tenant_id` do contexto.
- Se o super-admin estiver em **"Todas"**, a criação de unidade **exige** `tenant_id` no corpo (validação condicional: obrigatório **apenas** quando `TenantContext::id()` é nulo). Nunca criar recurso de negócio com tenant nulo.

### 7. SPA

- **Seletor de entidade** no `Topbar`: mostra "Todas" + entidades quando `pode_todas`; mostra só as `tenants_disponiveis` para os demais. Ao trocar: `POST /api/sessao/tenant` e **invalida o cache** do React Query (dados mudam de tenant).
- Novas páginas: `TenantsPage` (CRUD), `UsuariosPage` (CRUD, com multi-seleção de entidades e papel).
- `UnidadesPage`: botão **Editar** por linha (formulário reusa `NovaUnidadeForm` em modo edição; entidade exibida fixa) e ação **Desativar/Ativar**.
- Menu lateral: "Entidades" (super-admin) e "Usuários" (`usuario.gerenciar`).
- Tipos em `lib/api.ts`: `tenant_ativo`, `tenants_disponiveis`, `pode_todas` e os recursos novos.

### 8. Enforcement de "desativar"

- **Tenant desativado**: não aparece em `tenants_disponiveis`; o resolver não o seleciona (cai em "Todas"/outro vínculo); `POST /sessao/tenant` para ele é rejeitado; **login** de usuário cujo único vínculo é um tenant inativo é negado; unidades dele **não** entram no `DispatchSyncNfeJob` (o filtro de unidade ativa passa a exigir tenant ativo).
- **Usuário desativado**: `Auth::attempt` recusado (checagem no `AuthController::login`) e as rotas autenticadas rejeitam — sessões ativas caem na requisição seguinte.

## Riscos e casos de borda (o que os testes precisam fixar)

1. **`AuditLogger` audita com o tenant do usuário, não o da sessão.** Um super-admin operando no tenant A gravaria `tenant_id = null`. **Decisão:** `AuditLogger::log()` aceita `?int $tenantId = null` explícito; a ordem passa a ser `$tenantId ?? TenantContext::id() ?? $user?->tenant_id`. Jobs/CLI sem sessão passam o tenant explicitamente no call site. Custo se errado: trilha sem tenant ou com tenant errado.
2. **Super-admin em "Todas" criando unidade** → exige `tenant_id` (validação condicional, §6).
3. **Não-super-admin sem vínculo algum** → **403** (nunca contexto nulo). O contexto nulo só é válido para super-admin.
4. **Sessão aponta para tenant perdido/inativo** → resolver revalida e cai no fallback (§2).
5. **Auto-lockout**: admin-tenant não altera a si próprio em papel/vínculo/ativo.
6. **E-mail duplicado** é único **global** (login por e-mail) — `unique(users, email)` + mensagem clara.
7. **Troca de tenant e cache do SPA**: invalidar o React Query ao trocar (§7).
8. **Factory/helper de teste**: o `UserFactory` atual cria `tenant_id` mas não cria `tenant_user`. Criar um helper `User::factory()->comTenant($tenant)` que cria a pivô; revisar testes existentes que dependem do vínculo implícito.

## Abordagens de "tenant ativo" (registro da escolha)

- **A — sessão + validação (ESCOLHIDA):** grava `session('tenant_id')` via endpoint dedicado; middleware valida contra vínculos. Alinhado ao Sanctum por sessão; validação num só lugar.
- B — header `X-Tenant` por requisição: rejeitada (espalha o header; abas com tenants distintos; mais pontos de falha).
- C — coluna `users.tenant_ativo_id`: rejeitada (estado de UI no banco; conflita com múltiplos dispositivos).

## O que fica a cargo do construtor

- Nomes exatos de rotas/recursos e formato final do JSON (`tenant_ativo`, `tenants_disponiveis`).
- Componente do seletor e estilo.
- Ordem fina das tasks no plano (TDD), migração e seeders de teste.
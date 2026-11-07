# NFeGov 2.0

**NFE-Gov 2.0** — sistema multitenant de recepção e consulta de NF-e para entidades públicas.

Conecta-se periodicamente à SEFAZ (a cada 3 horas), via certificado digital e-CNPJ de cada
unidade gestora, para baixar todas as NF-e destinadas ao CNPJ da unidade, armazená-las e
disponibilizá-las para consulta.

Reescrita do [helesjunior/nfegov](https://github.com/helesjunior/nfegov) com Laravel 13 + PHP 8.4,
API REST e SPA React.

## Stack

| Camada | Tecnologia |
|---|---|
| Backend | Laravel 13 (API REST) |
| Frontend | React + Vite + TypeScript |
| Banco | PostgreSQL 16 |
| Fila / Cache | Redis + Laravel Horizon |
| Autenticação | Laravel Sanctum (SPA same-origin) + MFA (TOTP) |
| RBAC | spatie/laravel-permission |
| Integração SEFAZ | nfephp-org/sped-nfe ^5.2 |
| Infra | Docker + docker compose |

## Escopo fiscal

Este sistema faz **somente recepção e consulta** de NF-e:

- recepção do `distDFeInt` (consulta por NSU, incremental);
- consulta por chave de acesso;
- download do XML completo (após ciência da operação);
- manifestação do destinatário — **apenas ciência da operação** (`210210`), automática ao
  receber um `resNFe`;
- geração de DANFE (PDF) sob demanda.

Não há emissão de NF-e.

## Estrutura

```
nfegov2.0/
├── backend/          # Laravel 13 (API REST)
│   ├── app/          # domínio, jobs, controllers, policies
│   ├── database/     # migrations, seeders
│   ├── routes/       # api.php, console.php
│   ├── tests/        # Unit + Feature (inclui EndToEnd)
│   └── tools/        # scripts de apoio (seed de demonstração)
├── frontend/         # React + Vite (SPA)
├── docker/           # Dockerfile do PHP e config do Nginx
├── docs/             # design e plano de implementação
└── docker-compose.yml
```

## Requisitos

- Docker e Docker Compose
- (Desenvolvimento do frontend) Node.js 20+

## Como subir

```bash
# 1. Variáveis de ambiente
cp backend/.env.example backend/.env
#    Gere a chave e ajuste DB/Redis/mail conforme o ambiente
docker compose run --rm app php artisan key:generate

# 2. Suba a stack completa (app, nginx, postgres, redis, horizon, scheduler)
docker compose up -d --build

# 3. Migre o banco e popule os papéis/usuário inicial
docker compose exec app php artisan migrate --seed
```

A aplicação sobe em `http://localhost:8080`.

## Serviços

| Serviço | Papel |
|---|---|
| `app` | PHP-FPM (aplicação Laravel) |
| `nginx` | Front controller e arquivos estáticos (`/spa`) |
| `postgres` | Banco de dados |
| `redis` | Cache, fila e rate limiter global |
| `horizon` | Processamento das filas — `default` (exportações) e `sefaz` (sync) |
| `scheduler` | `schedule:work`: dispara o sync distDFe e os alertas de certificado |

## Operação

### Sincronização com a SEFAZ

O scheduler roda `SyncNfe` a cada **3 horas, em dias úteis** (ver `routes/console.php`).
Esse comando despacha o `DispatchSyncNfeJob`, que **escalona** as unidades ativas ao longo
da janela (`slot_window_minutes`, padrão 180 min) para não criar picos — o limite da SEFAZ é
por IP, e todos os tenants compartilham a mesma saída.

**Freio anti-bloqueio** (réplica dos limites do sistema legado):

- máx. 150 iterações e 100 documentos por execução;
- `sleep(2s)` entre as consultas;
- **para imediatamente** ao receber `cStat 656` (consumo indevido), abrindo um
  **circuit breaker global** que suspende todas as unidades por `circuit_breaker_minutes`
  (padrão 60);
- **rate limiter global** de `rate_per_minute` consultas/minuto para o servidor inteiro.

Cada execução fica registrada em `sync_runs` (status, NSU inicial/final, contagem de
documentos e erro), visível na aba **Sincronizações** da SPA.

**Sincronização só com certificado vigente.** Uma unidade cujo certificado e-CNPJ
esteja vencido (ou ausente) **não** consulta a SEFAZ: consultar com certificado inválido
consome NSU e é rejeitado — exatamente o caminho do bloqueio por "consumo indevido". A regra
é defendida em três camadas:

1. o `DispatchSyncNfeJob` **não enfileira** unidades sem certificado ativo vigente;
2. o `SyncNfeJob` **revalida** antes de executar (a fila pode envelhecer) e registra a
   execução como `bloqueado` — com o motivo — **sem tocar a SEFAZ**;
3. o `ToolsFactory` recusa montar o cliente com certificado vencido, cobrindo chamadas
   manuais fora dos jobs.

Para retomar a recepção de uma unidade, rotacione o certificado (tela da unidade); a
próxima execução do scheduler volta a sincronizá-la automaticamente.

### Manifestação do destinatário

Ao receber um `resNFe` (resumo), o sistema dá **ciência da operação** automaticamente e
reconsulta o download para obter o XML completo. Nenhuma outra manifestação é emitida.

### Certificado digital e-CNPJ

- Um certificado (PFX) **atual** por unidade. O upload é feito pela SPA, na tela da unidade.
- O PFX e a senha são **cifrados em repouso** (disco privado + `Crypt`); a senha **nunca** é
  exibida por nenhum endpoint.
- A rotação exige **step-up de MFA** (janela de MFA recente, padrão 15 min).
- Avisos de vencimento são enviados **apenas por e-mail** aos `admin-tenant`, nos marcos
  de 60/30/15/7/1 dias (`CheckCertificateExpiry`, diário às 07:00).
- O vencimento também fica **explícito no banco**: o comando `certificate-expiry:mark`
  (diário às 07:05) grava o carimbo `expirado_em` nos certificados **ativos** já vencidos.
  Ele **não** desativa o certificado (`ativo` continua significando "certificado corrente
  da unidade", usado pela rotação) — assim o vencido permanece visível no painel, nos
  alertas e na tela da unidade, agora com o estado explícito. O carimbo é idempotente e
  nunca reescreve uma marca já existente.
  Semântica: `foi_expirado` reflete o **carimbo** (`expirado_em`), gravado até ~24h depois do
  vencimento real (o comando roda 1×/dia). Para decidir "está vencido agora?", use o campo
  `vencido` (derivado de `valido_ate`); `foi_expirado=false` não garante "não vencido" na
  janela entre o vencimento e o próximo ciclo do comando.

### Filas

| Fila | Conteúdo | Worker |
|---|---|---|
| `default` | Exportações (CSV/XLSX/ZIP) | Horizon |
| `sefaz` | `DispatchSyncNfeJob`, `SyncNfeJob` | Horizon |

O painel do Horizon fica em `/horizon` (acesso restrito por gate).

### Exportações

Relatórios e ZIP de XMLs rodam em segundo plano (resposta **202**). Ao concluir, o usuário é
notificado (banco + e-mail) e baixa o arquivo por uma **URL assinada e temporária**
(`/api/exports/{arquivo}`), escopada ao tenant.

### XMLs fiscais

Documentos de guarda legal: **nunca** são descartados nem sobrescritos. Ficam **em disco**
(fora do banco — só o caminho é indexado) e cada download é auditado.

## Administração

O acesso é **multitenant**: cada usuário só enxerga os dados das entidades às quais está vinculado.
O vínculo vive na tabela **`tenant_user`** (N:N) e é a **fonte de verdade**; `users.tenant_id`
é mantido apenas por compatibilidade. Os papéis são globais (spatie/laravel-permission):

| Papel | Escopo |
|---|---|
| `super-admin` | Administra a plataforma: CRUD de **Entidades**, acesso a **todas** as entidades e de todos os usuários |
| `admin-tenant` | Administra a própria entidade: unidades, certificados e usuários vinculados a ela |
| `operador` | Operação do dia a dia (consulta/exporta NF-e, dispara sync) |
| `consulta` | Somente leitura de NF-e |

> O papel `admin-demo`, criado pelo seed de demonstração, existe **apenas** para inspeção local
> da interface (sem exigência de MFA) e **nunca** deve ser usado em produção.

### Seletor de entidade

No topo da SPA há um **seletor de entidade** que define o *tenant ativo* da sessão (gravado na
sessão e refletido em `POST /api/sessao/tenant`):

- **super-admin** pode escolher **"Todas as entidades"** (contexto nulo — sem filtro de tenant)
  ou qualquer entidade **ativa**;
- **demais usuários** só veem (e só podem selecionar) as entidades às quais estão vinculados.

Regras de disponibilidade:

- uma entidade **inativa** **não** aparece no seletor e **não** pode ser selecionada (tentar
  selecioná-la resulta em erro de validação); ela também **não** conta como vínculo;
- um usuário **sem vínculo ativo** (e que não seja super-admin) recebe **403** ao navegar
  ("Usuário sem entidade vinculada"), nunca um contexto nulo;
- a sessão guarda uma *preferência* de entidade; a cada requisição ela é revalidada — se a
  preferência deixar de ser acessível, o tenant ativo cai para o primeiro vínculo ativo.

### Entidades (tenants) — só super-admin

Tela **Entidades** (visível apenas com `pode_todas`): listar, criar, editar e ativar/desativar.
A criação exige **MFA confirmado** (papel `super-admin`). Desativar uma entidade a torna
indisponível no seletor e para seus vínculos; não apaga dados.

### Usuários — papel global + múltiplos vínculos

Tela **Usuários**: listar, criar, editar e ativar/desativar. Um usuário tem **um papel global** e
**um ou mais vínculos** com entidades:

- `super-admin` cria/atribui **qualquer** papel, inclusive `super-admin`, e vincula a qualquer entidade;
- `admin-tenant` só cria usuários com papéis **não**-super-admin e **apenas** vinculados às suas
  próprias entidades (403 caso contrário);
- listagem: um super-admin com contexto **"Todas"** vê todos os usuários; com uma entidade ativa
  (ou sendo admin-tenant) vê apenas os usuários vinculados àquela entidade;
- **anti-lockout:** ninguém altera a própria conta por esta tela (403) — evita auto-promoção.

Um usuário **inativo** **não** consegue logar (ver `POST /api/login`) e, se a sessão já estava
aberta, ela **cai na próxima requisição** (`SetTenantContext` responde 403 "Usuário inativo").

### Unidades — edição e desativação

Na tela da unidade é possível editar os dados cadastrais e **ativar/desativar**. A **entidade
(`tenant_id`) nunca muda** na edição — a unidade pertence à entidade em que foi criada; editar
não oferece (nem aceita) troca de entidade. Uma unidade **inativa** não é sincronizada com a SEFAZ.

## Variáveis de ambiente

Além das chaves padrão do Laravel, o NFeGov lê as seguintes (`config/nfegov.php`):

| Variável | Padrão | Descrição |
|---|---|---|
| `NFEGOV_SYNC_INTERVAL_HOURS` | `3` | Intervalo do agendamento (horas) |
| `NFEGOV_SYNC_SLEEP_SECONDS` | `2` | Pausa entre consultas distDFe |
| `NFEGOV_SYNC_WEEKDAYS_ONLY` | `true` | Sincronizar apenas em dias úteis |
| `NFEGOV_SEFAZ_RATE_PER_MINUTE` | `30` | Teto global de consultas/min (multitenant) |
| `NFEGOV_SEFAZ_CIRCUIT_BREAKER_MINUTES` | `60` | Pausa ao receber `cStat 656` |
| `NFEGOV_SEFAZ_SLOT_WINDOW_MINUTES` | `180` | Janela de escalonamento das unidades |
| `NFEGOV_SEFAZ_MAX_ITERACOES` | `150` | Máx. de consultas por execução |
| `NFEGOV_SEFAZ_MAX_DOCS` | `100` | Máx. de documentos por execução |
| `NFEGOV_SEFAZ_SLEEP` | `2` | Pausa entre consultas (por execução) |
| `NFEGOV_CERT_ALERT_DAYS` | `60,30,15,7,1` | Marcos de alerta de vencimento (dias) |
| `NFEGOV_CERT_STEP_UP_MINUTES` | `15` | Janela de MFA recente (step-up) |
| `NFEGOV_CERT_MAX_KB` | `5120` | Tamanho máximo do PFX (KB) |
| `NFEGOV_XML_DISK` / `NFEGOV_XML_ROOT` | `local` / `tenants` | Disco e raiz dos XMLs |
| `NFEGOV_CERT_DISK` / `NFEGOV_CERT_ROOT` | `local` / `certificados` | Disco e raiz dos PFX |

## Desenvolvimento

### Frontend

A SPA (React/Vite) é servida pelo próprio Laravel, a partir do build em
`backend/public/spa`. Para compilar a interface:

```bash
cd frontend
npm install
npm run build     # gera ../backend/public/spa
```

Para desenvolvimento com recarga a quente, rode `npm run dev` em `frontend/`
(porta 5173, com proxy de `/api` e `/sanctum` para `localhost:8000`).

> **Nota (Windows/macOS):** em desenvolvimento, o bind mount do código no
> container (Docker Desktop → WSL2) torna o boot do Laravel lento, pois o
> autoloader lê milhares de arquivos através da ponte de sistema de arquivos.
> **Solução já aplicada:** o `vendor/` fica num **volume Linux** (não no bind
> mount) — ver `vendordata` no `docker-compose.yml`. Isso derruba uma
> requisição de ~20s para ~0,2s. Se o volume sumir (ex.: `docker volume rm`),
> repopule com `docker compose exec -T app composer install`.
>
> Para acelerar ainda mais, cacheie config/rotas (opcional):
> `docker compose exec -T app sh tools/dev-fast.sh on`.
> **Antes de rodar os testes, limpe** (`... dev-fast.sh off`): os caches de
> config/rotas não podem coexistir com o `phpunit.xml`.

### Testes

Os testes usam **PostgreSQL** (`nfegov_test`) e desligam o `sleep` da SEFAZ via
`phpunit.xml`. A comunicação com a SEFAZ é sempre **mockada** (`SefazClient`).

```bash
# Backend (dentro do container)
docker compose run --rm app php artisan test

# Um único arquivo
docker compose run --rm app php artisan test --filter=SyncFlowTest

# Frontend
cd frontend && npm test
```

Há um teste **fim-a-fim** em `tests/Feature/EndToEnd/SyncFlowTest.php` que percorre o
fluxo completo (tenant → unidade → certificado → sync → import resumo/ciência/completo →
listagem via API), com a SEFAZ mockada.

### Dados de demonstração

```bash
docker compose exec app php tools/seed_demo.php      # tenant, unidade, usuários
docker compose exec app php tools/seed_nfe_demo.php  # uma NF-e de exemplo
```

O `seed_demo.php` cria a entidade, uma unidade, os usuários vinculados
(`admin@agu.gov.br`, `operador@agu.gov.br`, `admin.semmfa@agu.gov.br`) e um
**super-admin** de demonstração (`superadmin@agu.gov.br`, `tenant_id = null`, sem vínculo — usa
"Todas" no seletor), com MFA já confirmado. Senha de todos: `senha123`. O script imprime as
credenciais e o segredo TOTP do super-admin (útil para configurar o app autenticador).

## Versionamento

A versão semântica do sistema vive num **arquivo único** — `backend/VERSION`
(fonte de verdade única) — e é lida em runtime pelo backend
(`config('nfegov.versao.numero')`) e exposta por `GET /api/version`. O frontend
exibe no rodapé do menu: `NFeGov2.0 - v. <versão>-<AAAAMMDD.HHMMSS>`.

O timestamp é o **momento da build**, gravado pelo script
`frontend/scripts/build-info.mjs` em `backend/build.json` quando se roda
`npm run build` (nunca calculado em runtime). Assim o rodapé mostra de fato
quando aquele bundle foi construído. `backend/build.json` **não é versionado**.

Para publicar uma nova versão:

1. Edite `backend/VERSION` (ex.: `2.0.1`) e mantenha `frontend/package.json` em
   sincronia (o mesmo número em `version`).
2. Rode `cd frontend && npm run build` (gera o `build.json` e a SPA).
3. A versão aparece automaticamente no rodapé do menu e em `/api/version`.

### Fluxo de trabalho (pull request + controle de versão)

A partir da 2.0.0, **toda mudança entra por pull request** (a `main` é
protegida — configuração feita no GitHub). O fluxo:

```bash
# 1. Crie uma branch a partir da main atualizada
git checkout main && git pull
git checkout -b feature/minha-mudanca

# 2. Implemente, rode os testes (backend + frontend) e faça o commit
git commit -am "feat: descreve a mudança"

# 3. Abra o pull request
git push -u origin feature/minha-mudanca
# (abrir o PR no GitHub; a proteção da main exige revisão antes do merge)

# 4. Após o merge do PR, atualize `VERSION` conforme o tipo de mudança
```

Regra de versão (SemVer):
- **MAJOR** (`2.0.0` → `3.0.0`): mudança incompatível.
- **MINOR** (`2.0.0` → `2.1.0`): nova funcionalidade compatível.
- **PATCH** (`2.0.0` → `2.0.1`): correção de bug.

> **Nota:** a proteção de branch (exigir PR e revisão para a `main`) é
> configurada no painel do GitHub do repositório; este README documenta o
> processo adotado.

## Referência da API

Todas as rotas vivem sob `/api`, com autenticação por **Sanctum** (cookie de sessão, SPA
same-origin) e escopo de tenant por usuário autenticado.

| Método | Rota | Descrição |
|---|---|---|
| `GET` | `/api/version` | Versão + timestamp da build (público; rodapé do menu) |
| `POST` | `/api/login` | Autenticação (etapa 1) |
| `GET` | `/api/me` | Usuário atual, papéis/permissões, tenant ativo e entidades disponíveis |
| `POST` | `/api/sessao/tenant` | Define o tenant ativo da sessão (seletor de entidade) |
| `POST` | `/api/mfa/setup` · `/confirm` · `/verify` | Configuração e step-up do TOTP |
| `GET` | `/api/nfes` | Lista (filtros: período, unidade, emitente, valor, chave, busca) |
| `GET` | `/api/nfes/{chave}` | Detalhe com itens e link assinado do XML |
| `GET` | `/api/nfes/{chave}/xml` | XML (URL assinada) |
| `GET` | `/api/nfes/{chave}/danfe` | DANFE em PDF |
| `POST` | `/api/nfes/export` · `/export-xml` | Exportação assíncrona (202) |
| `GET` | `/api/notifications` | Notificações do usuário (exportações prontas) |
| `GET` | `/api/exports/{arquivo}` | Download (URL assinada) |
| `GET` | `/api/unidades` · `/api/unidades/{id}/certificado` | Unidades e certificado |
| `POST` | `/api/unidades` | Cria unidade (entidade vem do tenant ativo) |
| `PUT` | `/api/unidades/{id}` | Edita dados da unidade (entidade **não** muda) |
| `PUT` | `/api/unidades/{id}/ativo` | Ativa/desativa a unidade |
| `GET` · `POST` | `/api/tenants` | Entidades (listar/criar — só super-admin) |
| `GET` · `PUT` | `/api/tenants/{id}` | Detalha/edita entidade (só super-admin) |
| `PUT` | `/api/tenants/{id}/ativo` | Ativa/desativa entidade (só super-admin) |
| `GET` · `POST` | `/api/usuarios` | Usuários (listar/criar — papel global + vínculos N:N) |
| `PUT` | `/api/usuarios/{id}` | Edita usuário (papel e vínculos; não altera a própria conta) |
| `PUT` | `/api/usuarios/{id}/ativo` | Ativa/desativa usuário |
| `GET` | `/api/sync-runs` · `/api/dashboard` | Histórico de sync e painel |
| `GET` | `/horizon` | Painel do Horizon (restrito) |

## Documentação

- Design: [`docs/nfegov/2026-11-03-nfegov-design.md`](docs/nfegov/2026-11-03-nfegov-design.md)
- Plano de implementação: [`docs/superpowers/plans/2026-11-03-nfegov-2.0.md`](docs/superpowers/plans/2026-11-03-nfegov-2.0.md)

## Licença

MIT.
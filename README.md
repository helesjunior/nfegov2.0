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
> container (Docker Desktop → WSL2) torna cada requisição do Laravel lenta
> (alguns segundos), pois o bootstrap lê centenas de arquivos através da
> ponte de sistema de arquivos. Isso é uma característica do ambiente, não um
> defeito da aplicação. Em produção, o código é copiado para a imagem.

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

## Referência da API

Todas as rotas vivem sob `/api`, com autenticação por **Sanctum** (cookie de sessão, SPA
same-origin) e escopo de tenant por usuário autenticado.

| Método | Rota | Descrição |
|---|---|---|
| `POST` | `/api/login` | Autenticação (etapa 1) |
| `GET` | `/api/me` | Usuário atual e permissões |
| `POST` | `/api/mfa/setup` · `/confirm` · `/verify` | Configuração e step-up do TOTP |
| `GET` | `/api/nfes` | Lista (filtros: período, unidade, emitente, valor, chave, busca) |
| `GET` | `/api/nfes/{chave}` | Detalhe com itens e link assinado do XML |
| `GET` | `/api/nfes/{chave}/xml` | XML (URL assinada) |
| `GET` | `/api/nfes/{chave}/danfe` | DANFE em PDF |
| `POST` | `/api/nfes/export` · `/export-xml` | Exportação assíncrona (202) |
| `GET` | `/api/notifications` | Notificações do usuário (exportações prontas) |
| `GET` | `/api/exports/{arquivo}` | Download (URL assinada) |
| `GET` | `/api/unidades` · `/api/unidades/{id}/certificado` | Unidades e certificado |
| `GET` | `/api/sync-runs` · `/api/dashboard` | Histórico de sync e painel |
| `GET` | `/horizon` | Painel do Horizon (restrito) |

## Documentação

- Design: [`docs/nfegov/2026-11-03-nfegov-design.md`](docs/nfegov/2026-11-03-nfegov-design.md)
- Plano de implementação: [`docs/superpowers/plans/2026-11-03-nfegov-2.0.md`](docs/superpowers/plans/2026-11-03-nfegov-2.0.md)

## Licença

MIT.
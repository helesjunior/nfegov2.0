# NFeGov 2.0 — Documento de Design

**Data:** 2026-11-03
**Status:** aprovado (2026-11-03)
**Autor:** Heles Resende S. Júnior (sistema), consolidado em sessão de brainstorming

---

## 1. Intenção e o porquê

O NFeGov é um sistema de **recepção e consulta de NF-e** (modelo 55) para entidades
públicas. Ele se conecta periodicamente à SEFAZ, via certificado digital e-CNPJ de
cada unidade gestora, para baixar todas as NF-e **destinadas ao CNPJ** dessa unidade,
armazená-las e disponibilizá-las para consulta.

O sistema atual (`helesjunior/nfegov`) cumpre esse papel em Laravel 8 + Backpack, mas
está preso a um painel Blade pago, num PHP desatualizado, com a senha do certificado em
texto puro e sem isolamento multiusuário. O objetivo é reescrever a aplicação do zero,
elevando o nível de segurança, escalabilidade e experiência de uso, mantendo o backend
Laravel e adotando um frontend React próprio.

**Por que agora:** a base de código atual acumula dívidas que impedem crescimento
multientidade e a expõem a riscos de segurança no tratamento de credenciais fiscais.

---

## 2. Objetivos

- Reescrever o NFeGov em repositório novo, Laravel 13 + PHP 8.4.
- Backend como **API REST** pura, consumida por um **SPA React** no mesmo domínio.
- **Multitenancy** por entidade (órgão/prefeitura), banco único com `tenant_id`.
- **Segurança total** no tratamento do certificado e-CNPJ e das credenciais.
- Sincronização automática com a SEFAZ a cada 3 horas, robusta e observável.
- Interface nova, moderna e responsiva, substituindo integralmente o Backpack.
- Manter a funcionalidade fiscal existente: distDFe, consulta por chave,
  download por chave, manifestação (ciência da operação) e geração de DANFE (PDF).

## 3. Não-objetivos

- **Emissão** de NF-e/NFC-e (o sistema só recebe e consulta).
- Escrituração fiscal, apuração, SPED Fiscal, contabilidade.
- CT-e, MDF-e, GNRE, IBPT (o `sped-mdfe` existente hoje fica fora do escopo).
- Aplicativo mobile (a API REST deixa a porta aberta, mas não é meta desta fase).
- Integração com gov.br/SSO/LDAP nesta fase.
- Webhooks/WhatsApp/Telegram para alertas (somente e-mail nesta fase).

## 4. Anti-objetivos

Será considerado **fracasso** mesmo que tudo "funcione":

- Qualquer pessoa ou API conseguir **ler** a senha do certificado e-CNPJ.
- Um tenant conseguir ver, consultar ou inferir dados de outro tenant.
- Vazamento do arquivo `.pfx` (mesmo criptografado) para fora do servidor.
- Sincronização parada por certificado vencido **sem** alerta prévio ao admin.
- Perda do XML original de uma NF-e recebida (é documento fiscal de guarda legal).
- Regressões fiscais silenciosas: baixar, mas corromper/não indexar a nota.

---

## 5. Constraints (restrições)

- **PHP 8.4** (Laravel 13 exige `>= 8.4`). A `nfephp-org/sped-nfe` declara `php >= 7.4`
  e **testa oficialmente o 8.4** (matriz de CI de 7.4 a 8.5) — a integração fiscal é segura nessa versão.
- `nfephp-org/sped-nfe` **^5.2** é a biblioteca de integração SEFAZ (de-facto do ecossistema,
  1.6k estrelas). Não há alternativa maior em estrelas.
- O serviço **distDFe opera apenas em ambiente de produção da SEFAZ** (como já consta
  em `NfeOrg::consultaSefazDistDFe`). Homologação é limitada.
- SPA **no mesmo domínio** (decisão do dono do produto): Sanctum por cookie/sessão + CSRF.
- Repositório **novo**; o repo atual permanece intacto como referência.
- Um comando agendado depende de um **worker** ativo (Horizon) e de cron do servidor.

## 6. Decisões tomadas (o "como", conforme decidido)

| Tema | Decisão |
|---|---|
| Backend | Laravel 13 + PHP 8.4 |
| Frontend | SPA React (Vite) + API REST Laravel, mesmo domínio |
| Painel admin | Backpack **removido** integralmente |
| Multitenancy | **Instalação central multitenant**: banco único, `tenant_id` em todas as tabelas + global scope |
| Domínio | Único para todos (`nfegov.<orgao>` ou equivalente) |
| Escopo fiscal | Somente recepção e consulta de NF-e |
| Fila | Redis + Laravel Horizon |
| RBAC | `spatie/laravel-permission` |
| Autenticação | Laravel Sanctum (SPA, cookie same-origin) |
| MFA | TOTP obrigatório para `super-admin` e `admin-tenant`; **step-up** para ação sensível |
| Certificado | Por unidade; PFX cifrado em disco; senha `encrypted`; **nunca visível, só substituível** |
| Alertas de vencimento | Somente e-mail, marcos 60/30/15/7/1 dias |
| Modelo | 1 tenant → N unidades → 1 CNPJ + 1 e-CNPJ cada (~10 unidades/tenant em média) |
| Manifestação | **Somente ciência da operação (`210210`), automática** no sync |
| Freio SEFAZ | Freios por unidade **+ freio global por IP compartilhado** (ver 8.5) |
| Repositório | Novo |
| Deploy | SPA buildado para `public/`, `/api` servido pelo Laravel; **tudo em Docker** |
| Banco | **PostgreSQL 16** (driver `pgsql`) |

### 6.1 Decisões de escopo tomadas com a checagem de construtor

- **Ambiente SEFAZ:** `ToolsFactory` decide `tpAmb` a partir da config da unidade; distDFe
  **só produção**. Testes de integração usam **mock** das classes da `sped-nfe`.
- **Notas emitidas pelo próprio CNPJ da unidade** (que retornam no distDFe) **entram** no
  banco, marcadas com `emitente = próprio`.
- **Manifestação:** apenas ciência da operação (`210210`), disparada automaticamente
  quando chega `resNFe` (resumo), para liberar o XML completo. Sem eventos manuais.
- **XML é imutável e não excluível**, mesmo por `super-admin`; todo download auditado.
- **Exportação:** lista de NF-e em **CSV/XLSX**; XML em massa como **ZIP assíncrono**.
- **Resumo vs. completo:** `nfes` tem estado explícito (`resumo` → aguardando ciência →
  `completo`); `nfe_items` só é preenchido quando o XML completo chega.
- **Cursor NSU:** avança **por documento, em transação**, com idempotência por chave.
- **DANFE:** gerado com `nfephp-org/sped-da`.

---

## 7. Abordagens consideradas

**A) Laravel + Inertia.js + React (monolítico)**
Menos peças, autenticação trivial, um deploy. **Rejeitada**: Inertia não é API REST;
contraria o pedido explícito e fecha a porta para clientes externos.

**B) API REST Laravel pura + SPA React/Vite separado, mesmo domínio** ✅ **escolhida**
Atende literalmente "SPA React + API REST"; Sanctum same-origin mantém segurança simples;
dois projetos (`backend/`, `frontend/`) no mesmo monorepo, com separação limpa.

**C) API REST + SPA em repositórios/pipelines totalmente independentes**
Isolamento máximo. **Rejeitada**: colide com a decisão de SPA no mesmo domínio
(complexidade de CORS/cookies cross-site) sem ganho real nesta fase.

---

## 8. Arquitetura

### 8.1 Estrutura do monorepo

```
nfegov/
├── backend/          # Laravel 13 (API REST)
│   ├── app/
│   │   ├── Domain/               # regras de negócio, independentes do HTTP
│   │   │   ├── Nfe/              # recepção, normalização, persistência
│   │   │   ├── Sefaz/            # adaptadores para nfephp-org/sped-nfe
│   │   │   └── Certificate/      # ciclo de vida do e-CNPJ
│   │   ├── Http/Controllers/Api/ # controllers finos
│   │   ├── Jobs/                 # SyncNfeJob, etc.
│   │   ├── Console/Commands/
│   │   └── Models/
│   ├── database/
│   └── routes/api.php
├── frontend/         # React + Vite (SPA)
│   ├── src/
│   └── dist/         # build → backend/public
└── docs/
```

### 8.2 Camada de integração SEFAZ (o coração)

O código atual concentra a integração num trait `NfeOrg`. Na reescrita isso vira um
**serviço dedicado**, testável e desacoplado do HTTP:

- `Sefaz\DistDfeService` — cursor NSU, laço de consulta, decodificação de `docZip` (gzip),
  criação de `Nsu` e `Nfe` a partir do retorno `retDistDFeInt`.
- `Sefaz\ConsultaService` — `sefazConsultaChave`.
- `Sefaz\DownloadService` — `sefazDownload`.
- `Sefaz\ManifestacaoService` — `sefazManifesta` (ciência da operação).
- `Sefaz\ToolsFactory` — monta o `config.json` (a partir do cadastro da unidade) e injeta
  o `Certificate`, de forma única e testável (mockável nos testes).

A lógica de `montaConfig`, `buscaCertificado` e decodificação de `docZip` do legado é
reaproveitada como **referência**, não copiada como trait.

### 8.3 Modelo de dados (núcleo)

- `tenants` — entidade/órgão.
- `users` — pertencem a um tenant (ou super-admin global); relação de papéis via Spatie.
- `unidades` — pertencem a um tenant; 1 CNPJ + 1 IE + endereço + certificado ativo.
- `certificates` — histórico/versões do e-CNPJ por unidade:
  `unidade_id`, `tenant_id`, `arquivo_path` (cifrado), `senha_encrypted`,
  `titular`, `cnpj`, `valido_de`, `valido_ate`, `fingerprint`, `ativo`, `substituido_em`.
- `nsus` — cursor por unidade (`unidade_id`, `ultimo_nsu`, `max_nsu`, `dh_resp`).
- `nfes` — metadados da nota: `tenant_id`, `unidade_id`, `nsu_id`, `chave`,
  `numero`, `serie`, `modelo`, `emitente_cnpj/nome`, `dest_cnpj`,
  `valor_total`, `data_emissao`, `cstat`, `manifestacao`, `xml_path`, `schema`.
- `nfe_items` — itens da nota.
- `fornecedores` — emitentes normalizados por CNPJ.
- `municipios`, `estados` — apoio.
- `manifestacoes` — histórico de eventos enviados (tpEvento, sequência, retorno).
- `sync_runs` — auditoria de cada execução do job por unidade (início, fim, NSU
  inicial/final, quantos documentos, status, erro).
- `audit_logs` — trilha de auditoria (quem fez o quê, quando).
- `certificate_alerts` — alertas de vencimento emitidos (marco, data, destinatários).

**Isolamento:** trait `BelongsToTenant` com global scope aplicado a todo modelo de
domínio; `tenant_id` sempre presente; testes automatizados garantindo que nenhuma
query cruze tenants.

### 8.4 Armazenamento de XML

Decisão do designer (não exigida explicitamente, escolhida pela guarda legal e escala):

- XMLs **em disco** (filesystem `local`/S3), caminho por isto:
  `tenants/{tenant}/unidades/{unidade}/nfes/{ano}/{mes}/{chave}.xml`.
- No banco, apenas metadados e o `xml_path`.
- XML é documento fiscal de guarda legal → **nunca descartado**, com checksum.

### 8.5 Sincronização (a cada 3 horas)

- Comando agendado (`Schedule::command(...)->everyThreeHours()`), timezone
  `America/Sao_Paulo`, dias úteis, como hoje — **mas** disparando *jobs* por unidade,
  não um laço síncrono.
- `DispatchSyncNfeJob` distribui; `SyncNfeJob(unidade)` faz o trabalho de UMA unidade:
  1. Carrega o certificado ativo e descriptografa em memória (nunca em disco claro).
  2. Lê o `ultimo_nsu` da unidade.
  3. Laça `sefazDistDFe` respeitando `maxNSU` e o freio (abaixo).
  4. Decodifica `docZip` (`gzdecode(base64_decode(...))`), grava XML em disco,
     cria/atualiza `Nfe` + itens + emitente.
  5. Atualiza `Nsu` e registra `sync_runs`.
- **Idempotência** por chave de acesso (`updateOrCreate` por `chave`).
- **Unique job** por unidade para evitar concorrência no cursor NSU.
- Falha de certificado → `sync_runs` marca erro claro e dispara alerta.

#### 8.5.1 Freio contra bloqueio da SEFAZ (herdado do legado + proteção central)

O distDFe limita por **IP**, e ao violar devolve `cStat 656` (Consumo Indevido), que
bloqueia o acesso por ~1 hora. O legado já aplicava, **por unidade**:

- **Teto de iterações do laço:** `150` no primeiro catch-up (`ultNSU == 0`), `100` depois.
- **`sleep(2)`** entre iterações.
- **Break imediato no `cStat 656`**.
- Laço até `ultNSU == maxNSU`.

Numa **instalação central multitenant, todos os tenants compartilham um único IP de
saída**, o que o legado (single-tenant) não precisava tratar. Regra de ouro:
**o freio é global, não por tenant.** Camadas adicionais:

1. **Rate limiter global** (`RateLimiter`, chave única `sefaz-distdfe`) — teto de
   consultas/minuto no servidor inteiro, somando **todos** os tenants/unidades.
2. **Concorrência limitada de sync** no Horizon (1–2 workers para a fila `sefaz`),
   nunca laços paralelos pelo mesmo IP.
3. **Escalonamento na janela de 3h:** cada unidade recebe um *slot* dentro dos 180 min
   (ex.: unidade 1 no minuto 0, unidade 2 no minuto 18, ...), evitando rajadas.
4. **Circuit breaker global no 656:** se **qualquer** unidade receber `656`, a fila
   `sefaz` inteira pausa por ~1h (afeta todos os tenants), com estado persistido em
   cache/DB e retomada automática.
5. **Intervalo mínimo entre unidades** (gap entre o fim de um laço e o próximo).
6. **Cursor NSU por `unidade_id`** (como no legado), com idempotência por chave —
   garantindo retomada correta após pausa.

Parâmetros (teto global, gap, tamanho da janela) ficam em **config por instalação**
(`config/nfegov.php`), calibráveis sem deploy, pois o volume real varia por órgão
(referência de órgão grande: AGU, `nfe.agu.gov.br`).

### 8.6 Certificado e-CNPJ — segurança

1. **Upload/validação:** o `admin-tenant` envia `.pfx` + senha. A API abre o PFX
   (`NFePHP\Common\Certificate`), extrai CNPJ/titular/validade e **rejeita** se a senha
   for inválida, se o CNPJ divergir da unidade ou se já estiver vencido.
2. **Repouso:** arquivo cifrado em disco; senha com cast `encrypted` (AES-256 do Laravel,
   chave `APP_KEY` fora do banco). Nada em claro, nunca.
3. **Leitura:** **nenhum endpoint jamais devolve** o PFX ou a senha — nem para
   `super-admin`. A API expõe apenas metadados: titular, CNPJ, emissor, emissão, validade,
   dias restantes, status.
4. **Rotação:** upload transacional; o novo só substitui o ativo após validado; versões
   antigas ficam em histórico (auditoria) com `ativo = false`.
5. **Vencimento:** comando diário varre certificados e alerta por e-mail em 60/30/15/7/1 dias;
   unidade com certificado vencido fica sinalizada no dashboard.
6. **Uso:** descriptografia apenas em memória, no worker, no momento da chamada SEFAZ.
7. **Step-up:** upload/rotação exige reautenticação MFA recente, mesmo para quem já logou.

### 8.7 Segurança de aplicação

- Sanctum SPA: cookie de sessão same-origin, CSRF ativo, `Secure`/`HttpOnly`/`SameSite=Lax`.
- MFA (TOTP) obrigatório para `super-admin` e `admin-tenant`.
- RBAC (Spatie): papéis `super-admin`, `admin-tenant`, `operador`, `consulta`.
- Políticas (Policies) por recurso, sempre checando tenant.
- Rate limiting nas rotas de API; validação por Form Requests.
- Headers de segurança; HTTPS obrigatório; segredos via `.env`/secret manager.
- Auditoria de ações sensíveis (upload/rotação de certificado, manifestação, acesso a XML).
- **Todo download de XML/DANFE é auditado** (quem, quando, qual nota); XML é imutável e
  **não pode ser excluído**, nem por `super-admin`.
- Download de XML por **URL assinada** e temporária, com checagem de tenant.

### 8.8 API REST (esboço)

```
POST   /api/login, /api/logout, /api/mfa/*
GET    /api/me
GET    /api/tenants                         (super-admin)
GET    /api/unidades
POST   /api/unidades
GET    /api/unidades/{id}/certificado       (metadados; nunca o PFX)
POST   /api/unidades/{id}/certificado       (upload/rotação)
GET    /api/nfes                            (filtros: período, emitente, unidade, valor, chave)
GET    /api/nfes/{chave}
GET    /api/nfes/{chave}/xml                (signed URL)
GET    /api/nfes/{chave}/danfe              (PDF)
POST   /api/nfes/{chave}/manifestar         (tipos permitidos)
GET    /api/sync-runs                       (status da sincronização)
GET    /api/dashboard                       (indicadores)
```

### 8.9 Frontend (React)

- Vite + React + TypeScript, roteamento por cliente, estado de servidor com
  TanStack Query (cache/retry), autenticação via cookie.
- Telas: login (+MFA), dashboard, lista de NF-e (filtros/exportação), detalhe da NF-e
  (com PDF/XML), certificados por unidade (status/validade/rotação),
  unidades, usuários/papéis, execuções de sincronização, auditoria.
- Design system próprio (tokens, tema claro/escuro, responsivo), sem Backpack.

---

## 9. Restou ao construtor (decisões delegadas)

- Escolha do banco em produção: **PostgreSQL** (decidido — Docker em tudo).
- Provedor de e-mail (SMTP/API) e templates dos alertas.
- Biblioteca TOTP específica e fluxo de recuperação de MFA.
- Detalhes de UI/tema do SPA (dentro do design system a definir).
- Estratégia de backup/retensão dos XMLs e do banco.
- CI/CD, ambiente de homologação e observabilidade (logs/métricas do Horizon).

---

## 10. Referências

- `nfephp-org/sped-nfe` ^5.2 (docs: Funcionalidades, Tools, Standardize, Complements).
- Repositório legado: `helesjunior/nfegov` (referência de integração SEFAZ).
- Stack: Laravel 13, PHP 8.4, Sanctum ^4.3, Horizon ^5.50, spatie/laravel-permission ^8.3.

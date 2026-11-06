# Recuperação manual de NF-e (por chave e por NSU) — Design

**Data:** 2026-11-06
**Status:** aguardando aprovação

## Contexto e motivação

O DistDFe funciona como uma **fila descartável**: ao informar `ultNSU`, a SEFAZ
entende que aqueles documentos foram **consumidos** e avança o cursor. Reconsultar
o mesmo intervalo devolve `cStat 137` ("Nenhum documento localizado"). Os documentos
ficam na fila por apenas **~60 dias** e então somem definitivamente.

Hoje o sistema só sabe consumir essa fila **para frente** (`SefazClient::distDFe`
com `ultNSU`). Não há como **recuperar um documento específico** que já passou pela
fila — nem por chave de acesso, nem por NSU exato.

Durante o diagnóstico da unidade 17 (Congregação, `maxNSU=23921`), tornou-se claro
que essa lacuna é real: se um documento for perdido (app caiu, falha de processamento,
bug), **não há como resgatá-lo**. A boa prática — e a necessidade operacional — é
permitir a busca pontual.

A SEFAZ oferece **duas formas** de consulta pontual que o sped-nfe já suporta
(`Tools::sefazDistDFe(int $ultNSU, int $numNSU, ?string $chave, string $fonte)`):

1. **`consChNFe`** — consulta pela **chave de acesso** (44 dígitos).
2. **`consNSU`** — consulta por um **NSU exato** (um ponto específico da fila).

## Objetivo

Dar ao **usuário final**, na SPA, a opção de **buscar manualmente** uma NF-e que já
passou pela fila do DistDFe — **por chave de acesso** ou **por NSU específico** — e
ter o XML resultante disponível na tela de notas, exatamente como uma nota sincronizada
automaticamente.

A rotina automática de sincronização **continua** existindo e funcionando em paralelo.

## Goals

- Busca manual por **chave** (`consChNFe`): ciclo completo — se vier resumo (`resNFe`),
  envia **Ciência da Operação (210210)**, baixa o XML completo e importa.
- Busca manual por **NSU** (`consNSU`): consulta o NSU exato e importa o documento,
  com o mesmo ciclo acima quando o schema for `resNFe`.
- Operação **assíncrona** (enfileirada), respeitando o freio global (`SefazGuard`,
  circuit breaker, rate limiter) e a fila `sefaz`.
- Feedback ao usuário por **notificação** ao final (sucesso, não encontrado, bloqueado).
- O XML importado aparece na **tela de notas** como qualquer outro.

## Non-goals

- **Eventos** (`resEvento`: cancelamentos, CCe, manifestações de terceiros) **não** são
  importados nem armazenados nesta versão. Ficam para uma evolução futura.
- Não há **varredura automática de lacunas** (reconciliação) — a recuperação é **manual**.
- Não há busca por chave no sentido de "consulta de situação da NF-e" (`sefazConsultaChave`)
  — usamos exclusivamente o **distDFe** (`consChNFe`), que é o canal de recebimento.

## Anti-goals

- **Rebobinar o cursor** (`ultimo_nsu`) da unidade. Uma recuperação **jamais** deve
  alterar o `Nsu` da unidade — senão o usuário buscando um NSU antigo faria a
  sincronização automática "voltar" e re-consumir a fila (trabalho repetido + risco de 656).
- **Vazar documentos entre tenants/unidades**: o XML recuperado é sempre gravado no
  tenant/unidade do contexto, com o global scope aplicado.
- **Virar um "botão de 656"**: cliques repetidos do usuário não podem martelar a SEFAZ
  (a fila `sefaz` + `ShouldBeUnique` + freio global protegem isso).

## Constraints

- Backend Laravel; frontend React + API REST (SPA Vite, mesmo domínio).
- Multitenancy: banco único, `tenant_id` + global scope; tenant ativo na sessão.
- Sem libs novas.
- O `SefazClient` é a abstração isolada da SEFAZ (mockável em testes).
- O freio é **global** (`SefazGuard`), pois todos os tenants compartilham o IP.
- A operação pode ser **longa** (Ciência + propagação da SEFAZ) → assíncrona.

## Decisões tomadas com o parceiro

- A busca **por chave pode enviar a Ciência da Operação (210210)** automaticamente,
  como na rotina automática. (Risco já aceito: evento real e irreversível.)
- A busca **por NSU importa só NF-e**; eventos ficam para depois.
- Execução **assíncrona** (Opção B), com notificação ao final.
- **Abordagem 1**: novo `RecuperacaoService` no domínio, reaproveitando a lógica de
  processamento de documento, **sem tocar o cursor**.
- **Autorização: permissão `sync.executar`** (não `nfe.view`). A busca manual conversa
  com a SEFAZ e pode enviar a Ciência (evento irreversível) — é a mesma natureza da
  sincronização, que `sync.executar` já governa. Isso exclui o papel somente-leitura
  `consulta` de disparar a operação.
- **Sem retry automático da Ciência**: depois de enviar a Ciência, o job faz **uma**
  tentativa de baixar o XML completo. Se a SEFAZ ainda não propagou, o usuário é
  notificado honestamente ("ainda não disponível; tente novamente em alguns minutos") e
  pode reenviar a busca depois. (Sem re-tentativas/`backoff` nesta versão.)

## Abordagens consideradas

1. **`RecuperacaoService` novo, reusando a lógica de processamento (escolhida).**
   - Serviço de domínio com `porChave()` e `porNsu()`. Expõe `distDFePorNsu()` /
     `distDFePorChave()` no `SefazClient`. Extrai de `DistDfeService::processarDoc` a
     lógica comum (resumo → Ciência → completo → importa) para um colaborador
     (`DocumentoProcessor`). **Não toca o `Nsu`.** Roda em job enfileirado.
   - **Por que:** atende as duas formas (chave **e** NSU), isola o cursor com segurança
     e reaproveita 100% da importação, sem duplicar o ciclo resumo→Ciência→completo.
2. **Métodos no `DistDfeService`, sem novo serviço.**
   - Menos arquivos, porém mistura duas semânticas (varredura da fila vs. recuperação
     pontual) no mesmo serviço e dificulta isolar o cursor.
3. **Reusar `ConsultaService`/`DownloadService` (só por chave).**
   - Não resolve o `consNSU`; atenderia só metade do pedido. Descartada.

## Arquitetura

### Refatoração: `DocumentoProcessor`

Extrair do `DistDfeService` (linhas ~88-95 e ~149-186) um colaborador responsável por
transformar **um** `docZip` (nsu, schema, conteudo) em nota no banco:

```
App\Domain\Nfe\DocumentoProcessor
    processar(Unidade, ?Nsu, array{doc})   // igual ao processarDoc atual
    - resNFe  → importResumo + ciencia + download completo + import
    - procNFe/nfeProc → import
    - outros schemas → ignora (no-op)
```

- `DistDfeService` passa a injetar `DocumentoProcessor` e chama `processar()` no laço.
- `RecuperacaoService` também chama `processar()`. Comportamento idêntico ao automático.

### `SefazClient`: duas novas formas de consulta

Adicionar à interface (implementadas em `SpedSefazClient` via `sefazDistDFe`):

```php
// consNSU: consulta um NSU específico da fila (NSU >= 1; ver validação).
public function distDFePorNsu(Unidade $unidade, int $nsu): string;      // sefazDistDFe(0, $nsu, '', 'AN')

// consChNFe: consulta pela chave de acesso.
public function distDFePorChave(Unidade $unidade, string $chave): string; // sefazDistDFe(0, 0, $chave, 'AN')
```

**Atenção (limitação do sped-nfe):** `sefazDistDFe(0, 0, ...)` e `numNSU = 0` **não**
produzem `consNSU` — o sped-nfe cai no ramo incremental (`distNSU`). Por isso o NSU
**0 é inválido** e a validação exige `nsu >= 1`.

A resposta é o **mesmo envelope** (`retDistDFeInt`) já interpretado por
`DistDfeService::parseRetorno`, agora reutilizável.

### `RecuperacaoService` (novo, `App\Domain\Sefaz`)

```php
class RecuperacaoService
{
    public function porChave(Unidade $u, string $chave): ResultadoRecuperacao;
    public function porNsu(Unidade $u, int $nsu): ResultadoRecuperacao;

    // Internamente:
    //  1. $guard->beforeCall($u)  — freio global
    //  2. $client->distDFePorNsu/PorChave(...)
    //  3. parseRetorno → cStat:
    //       '138' → tem docs → processa cada doc via DocumentoProcessor
    //       '137' → "Nenhum documento localizado" → resultado NAO_ENCONTRADO
    //       '656' → tripCircuitBreaker + resultado BLOQUEADO
    //       'outro' → resultado ERRO (com xMotivo)
    //  NUNCA altera o Nsu da unidade.
    //  Ao importar, passa Nsu = null (não resolve/cria a linha Nsu da unidade:
    //  isso tocar no cursor, que é proibido aqui).
}
```

Resultado tipado para alimentar notificação/log:
`ENCONTRADO` (com chave/nsu e `docs_count`), `NAO_ENCONTRADO`, `BLOQUEADO`, `ERRO`.
O rótulo vem do que **efetivamente entrou** no banco: se o `cStat` for 138 mas nenhum
NF-e foi importado (ex.: só veio `resEvento`, que é ignorado, ou o completo não pôde ser
baixado a tempo), o resultado **não** é `ENCONTRADO` — é o desfecho parcial honesto
(`NAO_ENCONTRADO` ou um aviso "ainda não disponível").

### Job: `RecuperarNfeJob` (novo, `App\Jobs`)

- `implements ShouldQueue, ShouldBeUnique` — unicidade por **unidade + parâmetro**
  (ex.: `recuperar-unidade-17-chave-<chave>` / `...-nsu-<nsu>`), evitando cliques duplicados.
- Roda no `TenantContext::runAs($unidade->tenant_id, ...)`, na fila `sefaz`.
- Revalida **certificado vigente** e **circuit breaker** (igual ao `SyncNfeJob`); se
  falhar, notifica o usuário e encerra sem tocar a SEFAZ.
- Ao final, **notifica o usuário** que solicitou (canal `database`), com o desfecho.
- **Unicidade ≠ entrega:** como a unicidade é global (unidade + parâmetro), um segundo
  pedido idêntico enquanto o primeiro ainda está na fila é **suprimido**. O controller
  **detecta** a supressão (o job não é enfileirado) e responde **409 "já em andamento"**
  ao segundo solicitante — que, de outro modo, ficaria sem o job e sem notificação.
  O `RecuperarNfeJob` é disparado com o `user_id` do solicitante para a notificação.

### API

Rotas novas (grupo de negócio, `auth:sanctum` + `tenant` + `mfa`):

```php
POST /api/nfes/recuperar          // body: { unidade_id, chave } OU { unidade_id, nsu }
```

- Autorização: permissão **`sync.executar`** (a operação conversa com a SEFAZ e pode
  enviar a Ciência; mesma natureza da sincronização). O papel somente-leitura `consulta`
  **não** tem essa permissão, então fica de fora — decisão consciente (ver "Decisões").
  **Não** exige step-up de MFA (a Ciência automática já corre hoje sem step-up no sync).
- **Escopo da unidade:** a `unidade_id` é validada pelo **`UnidadePolicy::view`** da
  unidade-alvo (respeita as unidades vinculadas do usuário e o super-admin), não apenas
  por um `exists` no tenant ativo.
- Validação: exatamente **um** de `chave` (44 dígitos) ou `nsu` (inteiro **≥ 1**); unidade
  do escopo do usuário.
- Efeito: enfileira `RecuperarNfeJob` (com o `user_id` do solicitante) e responde **202**
  "busca agendada"; ou **409** se já houver busca idêntica em andamento.
- Auditoria: registra a solicitação (`AuditLogger`) — quem pediu, qual unidade, qual parâmetro.

### Frontend (SPA)

Na tela de **NF-e** (`NfeListPage`), um botão/ação **"Buscar NF-e"** abre um pequeno
formulário com:

- **Unidade** (select das unidades do tenant ativo);
- **Tipo de busca**: "Chave de acesso" ou "NSU";
- Campo correspondente (chave com máscara de 44 dígitos; NSU numérico);
- Botão **Buscar** → `POST /nfes/recuperar` → feedback "Busca agendada. Você será
  notificado." (ou 409 "já em andamento"; ou 403 se faltar `sync.executar`). A lista de
  notas se atualiza ao recarregar (não há polling/badge hoje — a notificação fica na
  central de notificações).

**Notificação (contrato existente a generalizar):** hoje a `Notificacao.dados` é
`{caminho?, formato?, total?}` e a `NotificacoesPage` imprime um texto **fixo** de
exportação. Para suportar os desfechos da recuperação, o payload da notificação ganha um
campo **`tipo`** (`exportacao` | `recuperacao`) + **`mensagem`**, e a página passa a
renderizar pela `mensagem`/`tipo` (mantendo o link de download quando houver `caminho`).
As notificações de recuperação **não** têm link de download — são informativas.

Reusa `nfeApi` (novo método `recuperar`) e ajusta `notificacoesApi`/`NotificacoesPage`.

## O que fica a critério do construtor

- **Formato exato do `ResultadoRecuperacao`** (enum vs. DTO) — seguir o estilo do projeto.
- **Registro de auditoria da recuperação**: usar `AuditLogger` (padrão do projeto) e/ou
  reaproveitar `SyncRun` (criando com `status` adequado e `erro` descritivo). Recomendação:
  `AuditLogger` para o pedido do usuário + `SyncRun` **não** é ideal (é varredura de fila);
  preferir log de auditoria + notificação. Decidir na implementação, sem inventar tabela.
- **Unicidade do job**: `uniqueId()` com hash do parâmetro para respeitar `ShouldBeUnique`.
- **Validação fina** (formato de chave, faixa de NSU) — seguir o estilo dos `FormRequest` existentes.
- **Texto/UX** das mensagens de notificação e do formulário.

## Riscos e mitigações

| Risco | Mitigação |
|-------|-----------|
| Rebobinar cursor | `RecuperacaoService` **nunca** toca `Nsu`; teste dedicado garante isso. |
| Cliques repetidos → 656 | `ShouldBeUnique` no job + fila `sefaz` (workers=2) + `SefazGuard`. |
| Chave sem permissão de download | `consChNFe` sem Ciência devolve só resumo → fluxo envia Ciência e faz **uma** tentativa de baixar; se a SEFAZ ainda não propagou, resultado parcial honesto ("ainda não disponível"), com notificação. |
| Documento fora da janela de 60 dias | `cStat 137` → resultado `NAO_ENCONTRADO`, notificação clara. |
| Certificado vencido/ausente | Revalidação no job (igual ao sync), sem tocar a SEFAZ. |
| Documento de outro tenant | Global scope + gravação sempre no tenant/unidade do contexto. |
| Clique duplicado suprimido pelo `ShouldBeUnique` | Controller detecta a supressão e responde 409 ao segundo solicitante. |
| Nota já existente localmente | `NfeImporter` é idempotente por `chave` (`updateOrCreate`); a recuperação **reimporta** (atualiza), sem short-circuit. |
| Nsu vinculado à nota recuperada | Passa-se `null`: **não** resolvemos/criamos a linha `Nsu` da unidade (evita tocar o cursor). |

## Testes (TDD)

- **`DocumentoProcessorTest`**: resumo → Ciência → completo → import; procNFe → import;
  evento → ignorado (não cria Nfe).
- **`RecuperacaoServiceTest`**: por chave (mock do `SefazClient::distDFePorChave`) e por
  NSU (mock de `distDFePorNsu`); `cStat 137/138/656`; **garante que o `Nsu` da unidade não muda**.
- **`RecuperarNfeJobTest`**: certificado vencido e circuito aberto **não** tocam a SEFAZ;
  notificação criada ao final; supressão por `ShouldBeUnique` (409 no controller).
- **`RecuperarNfeRequest`/rota (feature)**: autenticação, tenant, autorização
  (**`sync.executar`**; `consulta` recebe 403), validação (chave **ou** nsu ≥ 1, nunca os
  dois/nenhum), 202 e enfileiramento, 409 em duplicidade, escopo de unidade via policy.
- **Frontend**: formulário de busca (chave/NSU), validação e chamada da API (Vitest);
  renderização das notificações por `tipo`/`mensagem`.
- Suíte completa deve continuar verde (hoje **212 testes / 550 asserções**).

## Rollout

- Backend + frontend juntos; sem migration (se `AuditLogger`/notificação bastarem).
- Testado em dev com um cenário real conhecido (ex.: recuperar por chave uma nota
  existente do tenant Q-Tec/unidade 16, e um NSU da faixa da Congregação que devolve 137
  para validar o caminho "não encontrado").
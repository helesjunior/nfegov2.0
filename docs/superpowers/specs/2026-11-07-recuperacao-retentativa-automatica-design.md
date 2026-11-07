# Design — Retentativa automática da recuperação manual de NF-e

Data: 2026-11-07

## Contexto e motivação

A recuperação manual (`POST /api/nfes/recuperar`, por chave ou NSU) hoje
notifica o usuário **imediatamente** em qualquer desfecho que não seja sucesso.
Na prática, o desfecho mais comum é `nao_encontrado` — a SEFAZ ainda não tem o
documento na fila do destinatário (o emitente pode transmiti-lo horas depois) —
ou `bloqueado` (freio de consumo). Nos testes, a experiência se mostrou ruim:
o usuário precisa redigitar a **chave de 44 dígitos** para tentar de novo, e a
tentativa seguinte costuma bastar, porque a nota aparece depois.

## Objetivo

Quando a chave é válida (ou o NSU é coerente) e a nota **só não veio ainda**,
o próprio sistema reagenda a busca — até **3 tentativas automáticas**, nos
intervalos progressivos **30 min → 2h → 6h** — respeitando integralmente os
freios da SEFAZ. Só **após as 3 tentativas** sem sucesso o usuário é notificado
com o erro. Ele deixa de redigitar a chave.

## Decisões de design

### 1. Quais desfechos disparam reagendamento

O `RecuperacaoService` já classifica o desfecho em
`ResultadoRecuperacao`:

| Desfecho | Ação |
|---|---|
| `encontrado` | **Para** e notifica sucesso. |
| `nao_encontrado` | **Reagenda** (intervalo progressivo). |
| `erro` | **Reagenda** (intervalo progressivo). |
| `bloqueado` | **Reagenda, mas espera o freio fechar** antes (ver item 3). |

### 2. Mecanismo: job reagendador (Abordagem B)

Ao final de uma execução sem sucesso **retentável**, o job **despacha um novo
`RecuperarNfeJob` com `delay()`**, com o parâmetro `tentativa = atual + 1`.
Cada execução é um job novo e limpo — a unicidade e a idempotência funcionam por
execução, sem a interferência do `ShouldBeUnique` nem a confusão com "retry de
falha" do Horizon.

- Intervalos progressivos: `[30 min, 2h, 6h]` para as tentativas `1, 2, 3`.
- **3 tentativas automáticas** após a original (total de 4 execuções).
- `uniqueFor` passa a cobrir o maior delay (6h) — sobe para `24h`, para que a
  unicidade não expire antes do reenfileiramento. A liberação da chave de
  idempotência (`chaveIdempotencia`) continua ao fim de **cada** execução, para
  o usuário poder disparar manualmente se quiser.

### 3. `bloqueado` espera o freio

Se o desfecho for `bloqueado` (cStat 656 / circuit breaker / rate limiter), o
delay do reagendamento é **`max(intervalo, tempo_restante_do_freio)`**. Assim a
tentativa seguinte só ocorre depois que o circuit breaker fechou
(`circuit_breaker_minutes`, hoje 60 min) — não se queima tentativa contra porta
trancada. O número de tentativas **não é consumido** por espera de freio: só
contam as execuções que de fato consultaram a SEFAZ.

### 4. `SyncNfeJob` só no desfecho final

Hoje `RecuperarNfeJob` dispara `SyncNfeJob` ao final de **toda** execução. Isso
passa a acontecer **apenas no desfecho final** (sucesso/`encontrado`, ou a 3ª
tentativa esgotada). Reagendar **não** dispara o sync — senão multiplicaríamos
o consumo da SEFAZ a cada tentativa, contrariando o objetivo.

### 5. Notificação

- **Sucesso** (`encontrado`): notifica na hora (como hoje).
- **Reagendamento**: o usuário recebe um aviso imediato de que a busca foi
  agendada e será retentada automaticamente (sem precisar redigitar). O texto
  atual "Busca agendada. Você será notificado quando terminar." já cobre isso.
- **Falha final** (3 tentativas esgotadas): notifica o erro com os motivos.
  Nesse ponto a mensagem deixa claro que as tentativas automáticas se esgotaram.
- **`bloqueado` na 3ª**: mensagem honesta de freio de consumo.

## Escopo

**Dentro:**
- `RecuperarNfeJob`: parâmetro `tentativa`; decide reagendar; dispara
  `SyncNfeJob` só no desfecho final; ajusta `uniqueFor`.
- `RecuperacaoConcluida`: mensagem de "tentativas esgotadas" quando houver 3
  falhas; aviso de reagendamento.
- `SefazGuard`: expor o tempo restante do freio (para calcular o delay).
- Config `nfegov` para os intervalos (`recuperacao.backoff_minutes`) — sem
  hardcode, seguindo o padrão do projeto.
- Testes (unit/feature) cobrindo cada desfecho.

**Fora:**
- Persistência em banco/Comando agendado (Abordagem C) — desnecessário para 3
  tentativas; a fila Redis + `delay` cobre o caso.
- UI nova: o formulário e a central de notificações já existem; só o texto muda.

## Testes (TDD)

- `nao_encontrado` → reagenda com delay do 1º intervalo e `tentativa=2`.
- `erro` → reagenda.
- `bloqueado` → delay espera o freio; tentativa **não** é consumida.
- `encontrado` → **não** reagenda; notifica.
- 3ª tentativa falha → **não** reagenda; notifica "tentativas esgotadas".
- `SyncNfeJob` só é disparado no desfecho final.
- Mensagem `RecuperacaoConcluida` para as novas situações.
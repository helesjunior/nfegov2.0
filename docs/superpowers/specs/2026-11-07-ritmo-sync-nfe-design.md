# Ritmo da sincronização de NF-e (distDFe)

Data: 2026-11-07 · Versão: 2.7.0

## Pergunta

As rotinas de NF-e pareciam espaçadas demais. Quais esperas a SEFAZ exige e quais
eram cautela nossa?

## O que os registros mostraram (`sync_runs`, ambiente local)

| Unidade | Execuções agendadas (06/11, sexta) | Observação |
|---|---|---|
| 17 | 02:28, 05:28, 08:28, 11:28, 17:28, 20:28, 23:28 | sempre no minuto 28 (posição fixa na janela) |
| 16 | 02:58, 05:58, 08:58, 11:58, 17:58, 20:58, 23:58 | sempre no minuto 58 |

- Depois de sexta 23:28 a próxima execução agendada seria segunda 00:28: **49 h** sem
  consulta, porque o agendamento era fixo em "a cada 3 h, só em dias úteis".
- As configurações `NFEGOV_SYNC_INTERVAL_HOURS` e `NFEGOV_SYNC_WEEKDAYS_ONLY`
  existiam e estavam documentadas, mas o agendador não as lia.
- Os dois `656` (consumo indevido) registrados foram causados por nós:
  - 06/11 16:14:06 a fila da unidade 17 foi lida até o fim (`ultNSU = maxNSU`);
    19 s depois, 16:14:25, uma nova consulta (a sincronização disparada pela busca
    manual) recebeu `656`;
  - 07/11 13:54 outro `656` na mesma unidade, também fora do agendamento.
- No histórico inicial (06/11 02:20 a 02:43) a unidade 17 precisou de 4 execuções de
  150 consultas (≈ 6 min cada) para atravessar 23.800 NSUs; nesse caso a regra de
  1 h não se aplica, porque a fila ainda não estava vazia.

## Regras da SEFAZ (justificadas)

| Regra | Origem | Mantida |
|---|---|---|
| Depois de uma resposta com `ultNSU = maxNSU` (fila vazia), esperar **1 hora** antes de consultar de novo | NT 2014.002 e a própria mensagem do `656` ("Tente após 1 hora") | Sim, agora por unidade, com 61 min |
| Usar sempre o `ultNSU` devolvido nas consultas seguintes | idem | Sim (cursor por unidade) |
| Ao receber `656`, parar | idem | Sim (circuit breaker global de 60 min) |

## Cautelas nossas

| Cautela | Antes | Agora |
|---|---|---|
| Intervalo do agendador | 3 h fixo | `NFEGOV_SYNC_INTERVAL_HOURS` (padrão 1 desde a 2.7.1) |
| Só dias úteis | fixo | `NFEGOV_SYNC_WEEKDAYS_ONLY` (padrão `false` desde a 2.7.1) |
| Execução que para no teto (150 consultas ou 100 documentos) com a fila ainda cheia | esperava a próxima rodada (3 h, ou o fim de semana) | a próxima parte entra em `NFEGOV_SEFAZ_CONTINUACAO_SECONDS` (60 s) |
| Janela de escalonamento | 180 min mesmo que o intervalo fosse menor | limitada ao intervalo menos 5 min |
| Pausa de 2 s entre consultas, teto global de 30/min, 1–2 workers | mantidos | mantidos |

## Mudanças

1. `nsus.fila_vazia_em`: horário em que a fila foi vista vazia. `Nsu::segundosParaLiberarFila()`
   devolve quanto falta para os 61 min (`NFEGOV_SEFAZ_ESPERA_FILA_VAZIA_MINUTES`).
2. `SyncNfeJob` não consulta durante a espera e se reagenda para o fim dela. Vale para o
   agendador, para `nfegov:sync-nfe --unidade=ID` e para o sync disparado pela busca manual.
3. A busca manual por chave não faz a varredura da fila durante a espera; devolve
   `bloqueado` com o horário e a retentativa automática espera a liberação sem gastar
   tentativa.
4. Execução que para no teto com documentos pendentes agenda a continuação.
   `ShouldBeUniqueUntilProcessing` + `WithoutOverlapping` garantem uma execução por
   unidade de cada vez.
5. `656` fecha a execução com `finalizado_em`, NSU final e o motivo da SEFAZ.
6. `retry_after` da fila Redis passou de 90 s para 960 s (maior que o `timeout` de 900 s
   das filas `sefaz`/`adn`). Com 90 s, num servidor com 2 workers, um sync longo voltava
   para a fila no meio da execução e o outro worker o marcava como falho.

## Comparação com a NFS-e (ADN)

A NFS-e usa lotes de 50, histórico dividido em várias execuções e agendamento de 3 h.
O ADN não tem a regra de 1 hora, então lá a cadência é só cautela. Nesta versão a NFS-e
não mudou.

# Diagnóstico: recuperação manual de NF-e não encontra nota que está na fila

**Data:** 2026-11-06
**Status:** corrigido e **confirmado de ponta a ponta em produção** (NF-e importada).
**Caso:** NF-e `53261008353835000307550020000014051729330115`

## Sintoma

Busca manual **por chave** e **por NSU** retorna `cStat 137` ("Nenhum documento
localizado") para uma NF-e cujo destinatário é comprovadamente a Congregação
(unidade 17 / tenant 19, CNPJ `00101980000119`), confirmado em DANFE impressa.

## Evidências (produção, certificado da unidade 17)

| Consulta | Resultado |
|---|---|
| `NFeConsultaProtocolo` (situação da nota) | `cStat 100` "Autorizado o uso da NF-e", nProt `253260080476104`, 31/10/2026 |
| `consChNFe` (distDFe por **chave**) | `cStat 137` |
| `consNSU` (distDFe por **NSU específico**) | `cStat 137` |
| `sefazDownload` por chave | `cStat 137` |
| **distDFe incremental por `ultNSU=23921`** | **`cStat 138` "Documento localizado"**, `ultNSU` → **23922** |
| Ciência da Operação (210210) direta | `cStat 135`, **`<CNPJDest>00101980000119</CNPJDest>`** |

- A chave é válida (DV confere); emitente `08353835000307` (ULTRABOX, Brasília/DF).
- `manifestacoes` estava **vazia** antes da intervenção (nenhuma Ciência gravada).
- Cursor local: `nsu 23921 = 23921`. Último sync (06/11 08:28): `23921 → 23921`, `docs=0`.
- Request montado está correto: `<distDFeInt versao="1.01"><tpAmb>1</tpAmb><cUFAutor>53</cUFAutor><CNPJ>00101980000119</CNPJ>...`.
- Endpoint AN produção correto: `https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx`.

## Causa raiz

A recuperação manual usa **exclusivamente** `consChNFe` (chave) e `consNSU` (NSU
específico). Quando uma NF-e está na fila do destinatário no formato **resumo
(`resNFe`)** — o estado inicial de uma nota recém-autorizada —, a SEFAZ **não a
devolve por chave nem por NSU específico**. Ela só é entregue na **varredura
incremental por `ultNSU`**.

Confirmado pelo próprio retorno da SEFAZ no erro 656:

> "Rejeicao: Consumo Indevido (Deve ser utilizado o ultNSU nas solicitacoes
> subsequentes. Tente apos 1 hora)"

Portanto:
- A nota **está disponível** (o `ultNSU` da SEFAZ avançou para 23922).
- `porChave()`/`porNsu()` **nunca** a encontram — daí o `137` e a conclusão
  enganosa "nenhum documento".

A sincronização automática também não a pegou porque, no último ciclo (08:28), a
fila ainda estava em 23921 sem a nota; ela entrou depois e nenhum ciclo rodou desde.

## Por que o "registra ciência sem retorno" era enganoso

Com `cStat 137` não vem `resNFe`, então o `DocumentoProcessor` **não roda** e
**nenhuma Ciência é enviada** pela recuperação. O texto da notificação de
`NAO_ENCONTRADO` ("Se você acabou de enviar a Ciência da Operação...") sugere o
contrário e precisa ser corrigido.

## Correção decidida (com o parceiro)

1. `RecuperacaoService::porChave` passa a:
   a. consultar `consChNFe`;
   b. se `137`: enviar a **Ciência (210210)** e reconsultar `consChNFe`;
   c. se ainda `137`: **fallback para varredura incremental** (`ultNSU`) a partir
      do cursor local, procurando a chave, **sem tocar `nsu.ultimo_nsu`**.
2. Também disparar uma **sincronização normal da unidade** (consome a fila e
   avança o cursor legítimo).
3. **Mensagens honestas** por caminho na notificação (`137` com Ciência enviada vs.
   `137` sem resumo).

## Requisitos preservados

- A recuperação **NUNCA** altera `nsu.ultimo_nsu`.
- Só NF-e (eventos ficam para depois).
- Sem libs novas; multitenancy (`tenant_id` + global scope); execução assíncrona.
- Evitar rajadas (risco de cStat 656).

---

## ADENDO — Causa raiz de 2º nível (a mais importante), confirmada

Ao validar a correção em produção, o sync normal da unidade 17 consumiu a fila
(`23921 → 23925`, `docs=4`) mas importou **0 NF-es**. Investigando, descobriu-se
que o `DocumentoProcessor` comparava o schema do `docZip` por **igualdade exata**:

```php
if ($doc['schema'] === 'resNFe') { ... }
if (in_array($doc['schema'], ['procNFe', 'nfeProc'], true)) { ... }
```

Mas a SEFAZ em **produção** rotula o schema com sufixo de versão/arquivo:

- `procNFe_v4.00.xsd`
- `resNFe_v1.01.xsd`

Resultado: **nenhum documento do distDFe era processado** (nem resumo nem
completo). Isso explica por que a unidade 17 (e qualquer unidade) **nunca
importou nada automaticamente**, apesar do cursor avançar. Os testes usavam o
schema "puro" (`procNFe`/`resNFe`), por isso nunca detectaram o bug.

Correção [672dfeb]: `DocumentoProcessor::schemaBase()` normaliza o rótulo
(`procNFe_v4.00.xsd` → `procNFe`; `resNFe_v1.01.xsd` → `resNFe`) antes de comparar.

### Verificação de ponta a ponta (sucesso)

Recuperação por NSU 23922 (`consNSU`, sem tocar o cursor):

- `status=encontrado docs=1`; cursor **inalterado** em 23925.
- NF-e importada: chave `53261008353835000307550020000014051729330115`,
  estado `completo`, vNF `122.87`, emit `08353835000307`, dest `00101980000119`.
- XML gravado em `tenants/19/unidades/17/nfes/2026/10/<chave>.xml` (16.487 bytes).

Suíte backend: **245 passed / 642 asserções**.
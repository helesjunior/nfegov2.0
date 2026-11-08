# Ordenação nas listas de NF-e e NFS-e (2.8.0)

## Pedido

Além dos filtros, poder ordenar as listas: por emissão (crescente ou decrescente), por
competência, por valor, por prestador/emitente em ordem alfabética e assim por diante.

## Decisões

- **No servidor.** A ordem vale para o resultado inteiro, não só para a página que está na
  tela. A paginação e os filtros continuam iguais.
- **Parâmetros da API:** `ordem` (qual coluna) e `direcao` (`asc` ou `desc`, padrão `desc`).
  `ordem` aceita só as chaves da lista branca de cada consulta (`NfeQuery::ORDENACOES`,
  `NfseQuery::ORDENACOES`). Qualquer outro valor responde 422. A coluna nunca vem do cliente.

| Lista | Chaves de `ordem` | Padrão sem `ordem` |
|---|---|---|
| NF-e (`GET /api/nfes`) | `emissao`, `emitente`, `numero`, `valor` | emissão, mais recente primeiro |
| NFS-e (`GET /api/nfses`) | `emissao`, `competencia`, `prestador`, `tomador`, `numero`, `municipio`, `valor` | segue o "Período por" (emissão ou competência), mais recente primeiro |

- **Como cada tipo ordena** (`App\Support\Ordenacao`):
  - nomes: alfabética sem diferenciar maiúsculas e acentos ("Água" fica com os "A");
  - número: o campo é texto, então "9" vem antes de "10";
  - datas e valores: ordem natural.
- **Vazios sempre no fim**, nos dois sentidos (ex.: resumo de NF-e ainda sem data de emissão).
- **Desempate pelo `id`**, para a paginação ser estável.
- **Exportações** (CSV, XLSX e ZIP) seguem na ordem de gravação: percorrem as notas com
  `chunkById`, que exige a ordem por `id` para manter a memória constante.

## Tela

Os cabeçalhos ordenáveis viram botões. O primeiro clique numa coluna usa o sentido mais útil
(datas, número e valor do maior para o menor; nomes de A a Z) e o segundo inverte. A seta
mostra a coluna e o sentido atuais, e `aria-sort` informa leitores de tela. Filtrar mantém
a ordem escolhida; trocar a ordem volta para a página 1.

A coluna Unidade e a Situação/Estado não ordenam (exigiriam junção ou não têm uso prático).
O backend da NFS-e também aceita `tomador`, útil para quem consome a API das notas emitidas.

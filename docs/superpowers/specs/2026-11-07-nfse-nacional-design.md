# Design — Recepção de NFS-e pelo Ambiente de Dados Nacional (ADN)

Data: 2026-11-07
Status: **fases 1 e 2 implementadas na 2.4.0** (recepção + telas). Decisões no
fim do documento; o que mudou em relação a esta proposta está em
"Implementação (2.4.0)".

## Contexto e motivação

O NFeGov2.0 já recebe as NF-e (modelo 55) de cada unidade pelo `distDFe` da
SEFAZ: cursor NSU por unidade, certificado e-CNPJ da própria unidade, freio
global anti-bloqueio, XML imutável em disco por unidade e telas de consulta.

As notas de **serviço** (NFS-e) ficam de fora. Desde a LC 214/2025 (art. 62)
os municípios devem adotar o padrão nacional da NFS-e e compartilhar os
documentos no **Ambiente de Dados Nacional (ADN)**, mantido pela Receita
Federal. O ADN expõe aos contribuintes uma API de distribuição por NSU,
equivalente ao `distDFe`, o que permite reaproveitar quase toda a arquitetura.

## O serviço existe? Sim: API de Contribuintes do ADN

### Fontes

Oficiais:

- [Manual dos Contribuintes — Guia para utilização das APIs do ADN, v1.0 de 12/02/2026](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/documentacao-atual/manual-contribuintes-apis-adn-sistema-nacional-nfse.pdf)
- [Portal NFS-e — APIs Prod. Restrita e Produção](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/apis-prod-restrita-e-producao) (lista dos Swaggers; atualizado em 20/08/2026)
- [Portal NFS-e — Documentação técnica](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica) (leiautes, Anexo I de DPS/NFS-e)
- Swagger de contribuintes: [produção restrita](https://adn.producaorestrita.nfse.gov.br/contribuintes/docs/index.html) e [produção](https://adn.nfse.gov.br/contribuintes/docs/index.html)

Secundárias (relatos de implementação, **não** oficiais, mas com chamadas reais
documentadas em produção em out–nov/2026):

- [bitize/bitmde — conhecimento sobre a distribuição ADN](https://github.com/bitize/bitmde/blob/main/.docs/conhecimento/nfse-nacional-adn-distribuicao.md), [PR #20](https://github.com/bitize/bitmde/pull/20), [issue #17](https://github.com/bitize/bitmde/issues/17), [issue #18](https://github.com/bitize/bitmde/issues/18)
- [Fórum NFS-e Brasil — consulta em lote via NSU](https://forum.nfsebrasil.com.br/t/consulta-sequencial-em-lote-de-nfs-e-no-ambiente-nacional-via-nsu/1421)
- [Fórum ACBr — acesso à API do ADN](https://www.projetoacbr.com.br/forum/topic/74320-acesso-%C3%A0-api-para-baixar-as-nfs-e-nacional-adn/)
- [Fenacon — DANFSe e descontinuação da API oficial (NT 008/2026)](https://fenacon.org.br/reforma-tributaria/danfse-tera-novo-padrao-nacional-e-api-oficial-sera-descontinuada-em-julho/)
- [Contábeis — obrigatoriedade para municípios em 2026](https://www.contabeis.com.br/noticias/71679/nfs-e-nacional-sera-obrigatoria-para-municipios-a-partir-de-2026/)

> Nesta pesquisa os hosts `adn.*` recusaram a conexão TLS sem certificado de
> cliente (`SEC_E_DECRYPT_FAILURE`), o que é coerente com mTLS obrigatório até
> para ler o Swagger. Por isso o contrato abaixo vem do manual oficial mais o
> Swagger transcrito pelas fontes secundárias. **Antes de implementar, baixar o
> Swagger com o certificado de uma unidade e versioná-lo em `docs/`.**

### O que o manual oficial confirma

- As APIs permitem ao contribuinte consultar os documentos fiscais de serviço
  "em que figure como emitente, tomador ou intermediário".
- Métodos: `GET /DFe/{NSU}` (documentos a partir do NSU) e
  `GET /NFSe/{ChaveAcesso}/Eventos` (eventos de uma NFS-e).
- A consulta pode usar certificado de **mesmo CNPJ raiz** do contribuinte; na
  consulta por NSU há um parâmetro para informar um CNPJ de consulta diferente
  do CNPJ do certificado (com validação da raiz).
- Existe ambiente de **produção restrita** para testes dos contribuintes.

### Contrato técnico (Swagger, via fontes secundárias)

| Item | Valor |
|---|---|
| Base produção | `https://adn.nfse.gov.br/contribuintes` |
| Base produção restrita | `https://adn.producaorestrita.nfse.gov.br/contribuintes` |
| Autenticação | TLS 1.2+ com **mTLS**, certificado ICP-Brasil A1/A3 de CNPJ. Sem token. |
| Distribuição | `GET /DFe/{NSU}?lote=true&cnpjConsulta={cnpj}` |
| Eventos de uma nota | `GET /NFSe/{ChaveAcesso}/Eventos` (devolve a NFS-e e os eventos) |
| Formato | JSON (`Accept: application/json`) |
| Tamanho do lote | até **50** documentos |
| XML | `ArquivoXml` em **GZip + Base64** (igual ao `docZip` da NF-e) |
| Chave da NFS-e | **50 dígitos** (a NF-e tem 44) |

Resposta (`LoteDistribuicaoNSUResponse`):

- `StatusProcessamento`: `DOCUMENTOS_LOCALIZADOS` / `NENHUM_DOCUMENTO_LOCALIZADO` / `REJEICAO`
- `LoteDFe[]`: `NSU`, `ChaveAcesso`, `TipoDocumento` (`NFSE`, `EVENTO`, …),
  `TipoEvento` (ex.: `CANCELAMENTO`, `CANCELAMENTO_POR_SUBSTITUICAO`,
  `CONFIRMACAO_TOMADOR`, `REJEICAO_TOMADOR`, `CANCELAMENTO_POR_OFICIO`…),
  `ArquivoXml`, `DataHoraGeracao`
- `Alertas[]`, `Erros[]` (`Codigo`, `Descricao`, `Complemento`), `TipoAmbiente`,
  `DataHoraProcessamento`

Comportamento observado em produção (fonte secundária):

- **Semântica do NSU igual ao `ultNSU` da NF-e**: `GET /DFe/0` traz a partir do
  NSU 1; o próximo pedido usa o último NSU recebido, sem somar 1.
- **Fim da fila = HTTP 404** com `NENHUM_DOCUMENTO_LOCALIZADO` / `E2220`. Não é
  erro. Não existe `maxNSU`.
- Rejeição pode vir com HTTP 400 e `Erros` preenchido (ex.: `E2245`, CNPJ de
  outra raiz sem procuração), às vezes com `StatusProcessamento` de sucesso.
  **Quem decide a falha é o HTTP + `Erros`**, não só o status.
- A fila traz **todo o histórico** do CNPJ desde o primeiro documento no ADN
  (ex.: 2023), não só o que chegou depois da primeira consulta.
- Voltar a um NSU anterior funciona; **não há equivalente ao cStat 656** nem
  limite de cadência observado (~120 chamadas seguidas).
- A fila **mistura papéis**: NFS-e em que o CNPJ é tomador, as que ele emitiu e
  eventos de terceiros. O papel é descoberto lendo `toma`/`prest`/`interm` no
  XML. O ADN não filtra.
- Vêm NFS-e geradas pela SEFIN Nacional e por sistemas próprios de municípios
  (`ambGer`), com leiautes 1.00 e 1.01 misturados.

### DANFSe

A API oficial de geração do DANFSe foi **descontinuada em 1º/07/2026** (NT
008/2026): o PDF passa a ser gerado pelo próprio sistema, seguindo o leiaute da
NT (A4, marca d'água "CANCELADA"/"SUBSTITUÍDA", "SEM VALIDADE JURÍDICA" em
homologação). O `sped-da` que já usamos para o DANFE não cobre o DANFSe
nacional (a confirmar).

### Eventos e manifestação do tomador

Cancelamentos e manifestações chegam na própria fila como `TipoDocumento =
EVENTO`. A **manifestação** pelo tomador (Confirmação `e203202`, Rejeição
`e203206`) é feita na **SEFIN Nacional** (`https://sefin.nfse.gov.br/SefinNacional`),
com pedido XML assinado. Diferente da NF-e, não é preciso manifestar para
receber o XML completo: a NFS-e já vem inteira.

### Cobertura por município

Pela LC 214/2025, desde 01/01/2026 todo município deve usar o Emissor Nacional
**ou** integrar o sistema próprio ao ADN (convênio). Nota de município que
ainda não compartilha com o ADN **não aparece** na distribuição. Para esses
casos não existe API nacional: só o webservice próprio de cada prefeitura
(ABRASF, ISS.net etc.), fora do escopo deste plano.

## Como encaixa no NFeGov2.0

| Peça atual (NF-e) | Equivalente NFS-e | Reuso |
|---|---|---|
| `SefazClient` / `SpedSefazClient` (SOAP via sped-nfe) | `AdnClient` / `HttpAdnClient` (REST, Laravel `Http`) | novo, mesmo desenho de interface + fake de teste |
| `DistDfeService` (laço por NSU) | `DistNfseService` | mesmo laço; fim = 404 em vez de `maxNSU` |
| `EnvelopeDistDfe` + `DocZipDecoder` | `RespostaAdn` (JSON) + `DocZipDecoder` | decoder reaproveitado (gzip+base64) |
| `DocumentoProcessor` / `NfeImporter` | `NfseProcessor` / `NfseImporter` | novo parser do XML `NFSe` |
| `XmlStore` | `XmlStore` com pasta `nfses/` | estender com tipo de documento |
| `Nsu` (cursor por unidade) | `NfseNsu` (cursor por unidade) | tabela própria |
| `SyncRun` | `SyncRun` com coluna `tipo` | estender |
| `SefazGuard` (rate + circuit breaker) | mesmo guard, canal `adn` | parametrizar a chave do cache |
| `SyncNfeJob` / `DispatchSyncNfeJob` | `SyncNfseJob` / `DispatchSyncNfseJob` | mesmo padrão (`ShouldBeUnique`, slots) |
| `CertificateLoader` | idem | reaproveitado; PFX → PEM em memória |

### 1. Modelo de dados (migrations novas)

`nfses` — uma linha por NFS-e **por unidade** (mesma regra da NF-e desde 2.3.0):

- `tenant_id`, `unidade_id`, `nfse_nsu_id` (nullable)
- `chave` `string(50)`; `unique(unidade_id, chave)`
- `nsu` (bigint, NSU do ADN em que chegou)
- `papel` `string(16)`: `tomador` / `prestador` / `intermediario`
- `numero`, `codigo_municipio_emissor`, `municipio_emissor_nome`
- `prestador_cnpj_cpf`, `prestador_nome`, `tomador_cnpj_cpf`, `tomador_nome`
- `data_emissao` (`dhEmi` da DPS), `data_processamento` (`dhProc`), `competencia` (`dCompet`)
- `codigo_servico` (`cTribNac`), `descricao_servico` (texto, truncado para lista)
- `valor_servico`, `valor_liquido`, `valor_iss`, `iss_retido` (bool)
- `situacao` `string(16)`: `normal` / `cancelada` / `substituida`
- `ambiente_gerador` (`ambGer`), `versao_leiaute`
- `xml_path`, `checksum`
- índices: `(unidade_id, data_emissao)`, `(tenant_id, prestador_cnpj_cpf)`, `(tenant_id, competencia)`

`nfse_eventos` — eventos recebidos (cancelamento, manifestações…):
`tenant_id`, `unidade_id`, `nfse_id` (nullable: o evento pode chegar antes da
nota), `chave`, `nsu`, `tipo_evento`, `data_evento`, `xml_path`, `checksum`;
`unique(unidade_id, chave, tipo_evento, nsu)`.

`nfse_nsus` — cursor por unidade: `tenant_id`, `unidade_id` (unique),
`ultimo_nsu`, `dh_resp`, `historico_concluido_em`. Tabela separada de `nsus`
para não mexer no caminho da NF-e (que usa `firstOrCreate` por `unidade_id`).

`sync_runs` — nova coluna `tipo` `string(8)` default `'nfe'` (`'nfse'` para o
ADN), para a tela de Sincronização mostrar as duas filas.

Mapeamento de campos do XML (`NFSe/infNFSe`, namespace
`http://www.sped.fazenda.gov.br/nfse`): `nNFSe`, `cLocIncid`/`xLocEmi`,
`dhProc`, `ambGer`, `emit` (prestador), `valores/vLiq`, `DPS/infDPS/dhEmi`,
`dCompet`, `prest`, `toma`, `interm`, `serv/cServ/cTribNac`, `xDescServ`,
`valores/vServPrest/vServ`, `trib/tribMun`. **Conferir cada caminho no Anexo I
(leiaute 1.01) antes de codificar**; os testes usam XMLs reais anonimizados.

### 2. Cliente ADN

```php
interface AdnClient
{
    /** GET /DFe/{nsu}?lote=true&cnpjConsulta= — devolve RespostaAdn (status HTTP + JSON). */
    public function distribuicao(Unidade $unidade, int $ultimoNsu): RespostaAdn;

    /** GET /NFSe/{chave}/Eventos — recuperação pontual de uma nota. */
    public function eventosPorChave(Unidade $unidade, string $chave): RespostaAdn;
}
```

`HttpAdnClient` usa o `Http` do Laravel (Guzzle/cURL):

- Base URL por `unidade.tp_amb` (1 produção, 2 produção restrita), em
  `config('nfegov.adn.base_url')`.
- mTLS: o PFX é aberto pelo `CertificateLoader` (já em memória) e passado ao
  cURL como **blob** (`CURLOPT_SSLCERT_BLOB`/`CURLOPT_SSLKEY_BLOB`, PHP ≥ 8.1),
  mantendo a regra "o PFX nunca toca o disco em claro". Se o cURL do container
  não suportar blobs, cair para arquivo temporário `0600` apagado no `finally`.
- `cnpjConsulta` sempre = CNPJ da unidade (cada unidade já tem o próprio
  certificado; filial com certificado da matriz também funciona pela regra da
  raiz).
- Timeout curto (30 s), sem retry automático dentro da chamada.
- Trata `404 + NENHUM_DOCUMENTO_LOCALIZADO` como fila vazia; HTTP ≥ 400 com
  `Erros` como rejeição; corpo não-JSON (`application/problem+json`) como erro.

### 3. Serviço de sync

`DistNfseService::sync(Unidade, SyncRun)`, mesmo esqueleto do `DistDfeService`:

1. Lê/cria `nfse_nsus` da unidade.
2. Laço até `max_iteracoes`: `guard->beforeCall('adn')` → `distribuicao()`.
3. Fila vazia (404/E2220) → encerra com sucesso.
4. Rejeição → `SyncRun` com erro (`Codigo: Descricao`) e para.
5. Para cada item do `LoteDFe`: decodifica, grava XML, importa (NFS-e ou
   evento); o cursor só avança **depois** de cada item gravado.
6. Lote com menos de 50 itens → fim da fila, encerra sem nova chamada.
7. Pausa `sleep_segundos` entre chamadas.

Itens cujo `ArquivoXml` não abre viram aviso no `SyncRun` e o cursor segue
(como o `docZip` corrompido), para não travar a fila.

Importação idempotente: `updateOrCreate` por `(unidade_id, chave)`. O
`XmlStore` recusa sobrescrever; se o arquivo já existe com o mesmo checksum,
reaproveita (mesmo tratamento da reconciliação da 2.3.0).

Evento `CANCELAMENTO*` → `nfses.situacao = cancelada`; `*SUBSTITUICAO` →
`substituida`. Se a nota ainda não existe, o evento fica com `nfse_id` nulo e é
vinculado quando ela chegar.

### 4. Freio e agendamento

- `SefazGuard` ganha um **canal** (`'sefaz'` / `'adn'`) na chave do cache: o
  circuito do ADN não para a NF-e e vice-versa (hosts e IP-limites diferentes).
- No ADN não há cStat 656. O circuito abre em **HTTP 429/503** ou em N falhas
  de transporte seguidas (config `nfegov.adn.circuit_breaker_minutes`).
- `rate_per_minute` próprio (padrão 30) e fila Horizon própria `adn`.
- Agendamento: `sync:nfse` a cada 3 h em dias úteis, mesmo escalonamento por
  slot (`slotPara`), mesmas condições (unidade ativa, tenant ativo, certificado
  vigente).
- **Carga inicial**: como o ADN entrega o histórico inteiro, a primeira
  execução de uma unidade pode ter milhares de documentos. O teto `max_docs`
  por execução (ex.: 500) divide a carga em várias execuções; a tela mostra
  "carga histórica em andamento" até a primeira fila vazia
  (`historico_concluido_em`).

Config nova em `config/nfegov.php`:

```php
'adn' => [
    'habilitado' => (bool) env('NFEGOV_ADN_HABILITADO', false),
    'base_url' => [
        1 => env('NFEGOV_ADN_URL_PROD', 'https://adn.nfse.gov.br/contribuintes'),
        2 => env('NFEGOV_ADN_URL_HOM', 'https://adn.producaorestrita.nfse.gov.br/contribuintes'),
    ],
    'queue' => env('NFEGOV_ADN_QUEUE', 'adn'),
    'rate_per_minute' => (int) env('NFEGOV_ADN_RATE_PER_MINUTE', 30),
    'circuit_breaker_minutes' => (int) env('NFEGOV_ADN_CIRCUIT_BREAKER_MINUTES', 30),
    'max_iteracoes' => (int) env('NFEGOV_ADN_MAX_ITERACOES', 40),
    'max_docs' => (int) env('NFEGOV_ADN_MAX_DOCS', 500),
    'sleep_segundos' => (int) env('NFEGOV_ADN_SLEEP', 1),
    'timeout' => (int) env('NFEGOV_ADN_TIMEOUT', 30),
],
```

`habilitado` permite ligar a NFS-e por instalação sem afetar a NF-e.

### 5. Armazenamento do XML

Mesma raiz e mesma imutabilidade, pasta própria:

```
tenants/{tenant}/unidades/{unidade}/nfses/{AAAA}/{MM}/{chave}.xml
tenants/{tenant}/unidades/{unidade}/nfses/{AAAA}/{MM}/eventos/{chave}-{tipo}-{nsu}.xml
```

Ano/mês vêm da **competência ou `dhEmi` do XML**, não da chave (a posição do
AAMM na chave de 50 dígitos ainda precisa ser confirmada no leiaute). O
`XmlStore` passa a receber o tipo (`nfes`/`nfses`) e o período explícitos; a
chamada da NF-e continua igual.

### 6. API (backend)

Espelho das rotas de NF-e, com as mesmas políticas e o `?unidade_id=`:

- `GET /api/nfses` — filtros: `unidade_id`, período (emissão ou competência),
  `papel` (padrão `tomador`), `situacao`, prestador (CNPJ/nome), município, número
- `GET /api/nfses/{chave}?unidade_id=` — detalhe + eventos
- `GET /api/nfses/{chave}/xml?unidade_id=` — download do XML
- `POST /api/nfses/export` e `/export-xml` — reaproveitando `ExportNfeJob`/`ExportXmlJob`
- `POST /api/nfses/recuperar` — busca pontual por chave (`/NFSe/{chave}/Eventos`)
- `GET /api/nfses/{chave}/danfse` — **fase 3** (ver decisões)
- Permissões: as mesmas da NF-e (`nfe.view` e `nfe.export`).
- Auditoria (`AuditLogger`) em download de XML/PDF, como na NF-e.

### 7. Telas (frontend)

- Menu lateral: item **NFS-e** logo abaixo de NF-e (`/nfses`, permissão `nfe.view`).
- `features/nfses/NfseListPage`: mesmo layout da lista de NF-e; colunas número,
  prestador, município, emissão, competência, valor, ISS retido, situação;
  filtros por unidade, período, papel (Recebidas / Emitidas / Intermediadas),
  situação e busca por prestador.
- `NfseDetailPage` (`/nfses/:chave?unidade=ID`): dados do serviço, partes,
  valores e tributos, linha do tempo de eventos, botões XML (e PDF na fase 3).
- Tela de Sincronização: filtro `NF-e | NFS-e` e indicador de carga histórica.
- Painel inicial: card "NFS-e recebidas no mês" (opcional, fase 2).

### 8. Testes

Backend (PHPUnit, Postgres no Docker):

- `FakeAdnClient` com respostas JSON gravadas (fixtures em `tests/Fixtures/adn/`,
  XML real anonimizado, gzip+base64).
- `DistNfseService`: drena lotes de 50; fila vazia por 404/E2220 encerra com
  sucesso; rejeição E2245 vira `SyncRun` com erro e não move o cursor; `ArquivoXml`
  corrompido não trava a fila; cursor só avança após gravar; teto `max_docs`
  retoma do ponto certo; circuito do ADN independente do da SEFAZ.
- `NfseImporter`: papel tomador/prestador/intermediário; idempotência por
  `(unidade_id, chave)`; mesma chave em duas unidades; leiautes 1.00 e 1.01.
- Eventos: cancelamento antes e depois da nota.
- `XmlStore`: caminho `nfses/`, imutabilidade.
- `HttpAdnClient` com `Http::fake()`: URL, query, `Accept`, escolha do ambiente.
- Controllers: escopo de tenant, `?unidade_id=`, permissões `nfe.view`/`nfe.export`.

Frontend (Vitest): lista, filtros, detalhe, item do menu condicionado à permissão.

Teste manual: produção restrita com o certificado de uma unidade (precisa do
usuário), e depois uma unidade em produção com `max_docs` baixo.

### 9. Versionamento e entrega

Feature nova → **MINOR: 2.4.0** (`backend/VERSION`, `frontend/package.json` e
lock), commit `chore(release): 2.4.0 - recepção de NFS-e (ADN)`.

Fases sugeridas (cada uma um PR):

1. **Backend de recepção**: migrations, `AdnClient`, `DistNfseService`,
   importer, eventos, guard por canal, jobs, comando `sync:nfse`, testes. Flag
   `NFEGOV_ADN_HABILITADO=false` por padrão.
2. **Telas e API de consulta**: rotas, permissão, lista/detalhe/XML/exportação,
   Sincronização com filtro. Release 2.4.0.
3. **DANFSe local** (PDF no leiaute da NT 008/2026).
4. **Manifestação do tomador** na SEFIN Nacional (confirmar/rejeitar), se fizer sentido.

## O que não consegui confirmar

- O Swagger oficial diretamente: os hosts `adn.*` exigem certificado de cliente
  até para a documentação. Os nomes de campo vêm do manual + transcrição do
  Swagger por terceiros (out/2026).
- Limite de uso oficial do ADN. Não há número publicado; o "sem limite" é
  observação de terceiros em ~120 chamadas. Por isso mantemos freio e circuito.
- Se o `lote=false`/NSU único e a regra "fim = 404" seguem iguais hoje (relatos
  de out–nov/2026).
- Posição do ano/mês dentro da chave de 50 dígitos e os caminhos exatos de cada
  campo do XML (conferir no Anexo I, leiaute 1.01).
- Lista atual de municípios conveniados ao ADN e quais municípios das unidades
  ainda usam só sistema próprio.
- Se a consulta com `cnpjConsulta` de outra raiz funciona com procuração eletrônica.
- Se há retenção máxima do histórico no ADN.

## Decisões (usuário, 2026-11-07)

1. **Guardar todas as notas** (recebidas, emitidas e intermediadas) com `papel`;
   a tela abre em "Recebidas".
2. **Histórico completo**, dividido em várias execuções (`max_docs`).
3. **DANFSe** fica para uma fase seguinte.
4. **Manifestação do tomador** fica de fora por enquanto.
5. **Agenda** igual à da NF-e (a cada 3 h, dias úteis).
6. **Teste real** com a unidade Congregação Cristã no Brasil - Brasília e o
   certificado dela.

## Implementação (2.4.0)

Chamadas reais com o certificado da unidade de Brasília (só leitura):

- Produção restrita: `GET /DFe/0` → HTTP 404, `E2220`, `TipoConsulta: CnpjBase`.
  O mTLS com o certificado em blob PEM funciona.
- Produção: `GET /DFe/0` → HTTP 200, 50 itens (46 NFS-e e 4 cancelamentos,
  desde abril/2023), XML em GZip + Base64, leiaute 1.00. O Swagger
  (`/contribuintes/swagger/v1/swagger.json`, "API NFS-e - ADN Contribuinte v1")
  lista só `GET /DFe/{NSU}` (`cnpjConsulta`, `cpfConsulta`, `lote`) e
  `GET /NFSe/{ChaveAcesso}/Eventos`.
- A chave de 50 dígitos é município (7) + ambiente gerador (1) + tipo de
  inscrição (1) + inscrição (14) + número (13) + AAMM (4) + código (9) + DV (1).
  O XML vai para a pasta do AAMM da chave.
- O sync real da unidade importou 56 NFS-e e 4 cancelamentos, com papel
  `tomador` e situação `cancelada` aplicada pelos eventos.

Diferenças em relação à proposta:

- **Permissões**: em vez de criar `nfse.view`, a NFS-e usa `nfe.view` (consultar)
  e `nfe.export` (XML e exportação). Quem opera NF-e opera NFS-e, sem precisar
  rodar o seeder de papéis em produção.
- **Cliente HTTP**: cURL direto, porque o Guzzle não aceita
  `CURLOPT_SSLCERT_BLOB`. O certificado continua só em memória.
- **Fora desta versão**: exportação ZIP dos XMLs, recuperação manual por chave
  (`/NFSe/{chave}/Eventos` já está no cliente) e card no painel.
- Configuração em `nfegov.adn.*` (`NFEGOV_ADN_HABILITADO` liga/desliga; fila
  Horizon `adn` com `NFEGOV_ADN_WORKERS` processos).

## Fase 2 (2.5.0): DANFSe, manifestação, ZIP e busca por chave

Versão **2.5.0** (MINOR): quatro funções novas, sem quebrar nada do que já
existia. Um migration novo (`manifestacao` e `manifestada_em` em `nfses`).

### Busca manual por chave

`POST /api/nfses/recuperar` (`unidade_id` + chave de 50 dígitos), assíncrona na
fila `adn`, aviso em Notificações. Exige `sync.executar` e usa
`GET /NFSe/{chave}/Eventos` do ADN sem mexer no cursor NSU. Pedido igual em
andamento volta 409. Sem retentativa automática.

Conferido no ADN real: a chave de **outra unidade** volta `E2241` ("não
referencia o Contribuinte solicitante"), e chave inexistente volta `E2240`.
Ou seja, não dá para trazer nota de terceiros; o aviso mostra o código.

### ZIP de XMLs

`POST /api/nfses/export-xml`, mesmos filtros da lista. Uma pasta por CNPJ de
unidade, com os eventos de cada nota em `eventos/`.

### DANFSe local

`GET /api/nfses/{chave}/danfse?unidade_id=` gera o PDF na hora, a partir do XML
guardado, seguindo a [NT SE/CGNFS-e nº 008 v1.02](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/rtc/nt-008-se-cgnfse-danfse-20260714-v1-02.pdf)
(14/07/2026), que passou a geração do DANFSe para os sistemas: a API do ADN foi
suspensa em 03/08/2026.

O que segue a NT: A4 retrato em uma página, margens de 2 mm, borda de 1 pt,
linhas de 0,5 pt, sombreamento cinza nos blocos indicados, fontes 6/7/9 pt como
a NT define, QR Code para
`https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=` + chave (X 17,48 cm,
Y 1,67 cm), logomarca oficial da NFS-e no cabeçalho, "NFS-e SEM VALIDADE
JURÍDICA" em vermelho nas notas de homologação, marca d'água diagonal
CANCELADA/SUBSTITUÍDA (50 pt ou mais, cinza), frases das supressões (2.3.1) e
"-" nos campos sem dado.

O que **não** está igual à NT, para ninguém achar que está:

- Fontes: a NT pede Arial e Microsoft Sans Serif. Saem na Helvetica embutida
  do PDF (equivalente à Arial).
- Posição de cada campo: segue a ordem de blocos, os tamanhos e as regras
  escritas da NT, mas **não foi comparada com o desenho do Anexo I** (é uma
  figura no PDF). Vale conferir lado a lado com um DANFSe oficial.
- Canhoto (opcional): não é impresso.
- Bloco de IBS/CBS: só sai quando a nota o traz; os campos dele são um
  subconjunto dos listados na NT.
- Descrições de códigos (regime especial, imunidade, tipo de emitente etc.)
  foram escritas a partir do leiaute nacional e dos exemplos da NT; os códigos
  sem descrição conhecida saem como o número.
- Nome do município de tomador e intermediário: o XML só traz o código IBGE;
  o nome sai quando a própria nota o dá para o mesmo código (emissão,
  prestação ou incidência), senão sai o código.

Auditoria: `nfse.danfse.gerado`.

### Manifestação do tomador

`POST /api/nfses/{chave}/manifestacao` com `unidade_id` e `acao`
(`confirmar` ou `rejeitar`; a rejeição leva `motivo` 1 a 5 ou 9 e `descricao`
de 15 a 255 caracteres, obrigatória no 9). Ato fiscal registrado na **SEFIN
Nacional** (`POST /nfse/{chave}/eventos`, corpo
`{"pedidoRegistroEventoXmlGZipB64": ...}`, resposta 201 com o evento em
GZip+Base64):

- Confirmação = evento `203202`; Rejeição = `203206`. Pedido `pedRegEvento`
  v1.01, `Id` = `PRE` + chave + código, assinado em `infPedReg` com o
  certificado da unidade (RSA-SHA1, C14N) e enviado com a declaração
  `<?xml version="1.0" encoding="UTF-8"?>` (sem ela a SEFIN rejeita com E1229;
  sem assinatura, E0717).
- Só vale para nota em que a unidade é **tomadora**, normal (não cancelada nem
  substituída) e ainda sem manifestação. A confirmação impede o prestador de
  cancelar a nota (só substituir).
- Segurança: exige `nfe.export`, **MFA recente** (mesmo step-up da troca de
  certificado), aceite explícito na tela, trava contra clique duplo e nunca
  repete sozinho. Cada pedido, aceito ou recusado, fica na auditoria
  (`nfse.manifestacao.registrada` e `nfse.manifestacao.recusada`).
- O estado local (`manifestacao`, `manifestada_em`) é gravado assim que a SEFIN
  registra; quando o evento do tomador chega depois pela fila do ADN
  (`CONFIRMACAO_TOMADOR`/`REJEICAO_TOMADOR`), ele também atualiza a nota.
  O XML do evento devolvido fica em `.../eventos/{chave}-manifestacao-{código}.xml`.

**Como foi validado.** O contrato (rota, corpo, resposta, erros, leiaute do
pedido e assinatura) vem do Swagger da SEFIN lido com o certificado da unidade
(`https://sefin.producaorestrita.nfse.gov.br/SefinNacional/swagger/docs/v1`) e
do conhecimento publicado pelo projeto
[bitize/bitmde](https://github.com/bitize/bitmde/blob/main/.docs/conhecimento/nfse-nacional-sefin-eventos.md),
que registrou uma Confirmação real em produção. Aqui, na **produção restrita**,
um pedido assinado com chave **inexistente** voltou `E1831` (a nota não existe
no ADN): isso prova que codificação, schema e assinatura passaram, e nenhum
evento foi registrado. **Nenhuma manifestação real foi enviada** durante o
desenvolvimento: o primeiro uso real fica por conta do usuário, e a Rejeição
(`203206`) nunca foi exercitada contra o serviço, só a Confirmação por terceiros.

### O que continua de fora

Canhoto do DANFSe, card de NFS-e no painel e a manifestação de intermediário e
prestador.

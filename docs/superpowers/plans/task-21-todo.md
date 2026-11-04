# Task 21 — Frontend: lista e detalhe de NF-e + endpoints de export

## Escopo decidido com o usuário
Além das telas, fechar o **gap de exportação**: o backend agenda o job (202),
notifica por `ExportPronto`, mas **não há** rota de download nem de notificações.
Vamos criar:

1. `GET /api/notifications` — lista as notificações `database` do usuário
   (com `id`, `caminho`, `formato`, `total`, `read_at`, `created_at`).
2. `GET /api/exports/{arquivo}` (URL **assinada**) — baixa o arquivo de
   `exports/{tenant}/...`, validando que pertence ao tenant ativo, com
   `Content-Disposition: attachment`.

## Todo

### Backend
- [x] Step 1 — Teste que falha: `ExportDownloadTest`
- [x] Step 2 — rodar `php artisan test --filter=ExportDownloadTest` → FAIL
- [x] Step 3 — Implementar `NotificationController` + `ExportDownloadController`
      + rotas + migration `notifications`
- [x] Step 4 — rodar → PASS (5 passed); suíte completa 113 passed
- [x] Verificação end-to-end: 202 → Horizon processa → notificação → link assinado → download CSV OK

### Frontend
- [x] Step 5 — Teste que falha: `NfeListPage.test.tsx`
- [x] Step 6 — rodar `npm test` → FAIL
- [x] Step 7 — Implementar:
  - `features/nfes/NfeListPage` (filtros + paginação + export)
  - `features/nfes/NfeDetailPage` (itens, XML, DANFE)
  - `features/notificacoes/NotificacoesPage` (exportações prontas + download)
  - `lib/api.ts`: endpoints nfes, export, notifications
- [x] Step 8 — `npm test` (13 passed) + `tsc` + `build` (143 módulos) → PASS
- [ ] Step 9 — Commit `feat: nfe list/detail and export download`
- [ ] Step 10 — Push

## Contratos (mapeados)
- `GET /api/nfes` → paginado; item: id, chave, numero, serie, modelo, unidade_id,
  emitente_cnpj, emitente_nome, dest_cnpj, valor_total(string), natureza_operacao,
  data_emissao(ISO), cstat, estado(resumo|completo), emitente_proprio, tem_xml,
  xml_url(null na lista)
- `GET /api/nfes/{chave}` → `{data: {...}}` com `itens[]` e `xml_url` (5 min, só c/ nfe.export)
- `GET /api/nfes/{chave}/xml` (signed) → application/xml inline
- `GET /api/nfes/{chave}/danfe` → application/pdf inline
- `POST /api/nfes/export {formato:csv|xlsx, ...filtros}` → 202
- `POST /api/nfes/export-xml {..filtros}` → 202
- Filtros: de, ate, unidade_id, emitente_cnpj, valor_min, valor_max, chave, q, per_page(≤100), page
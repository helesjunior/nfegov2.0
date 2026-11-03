# Task 20 — Frontend: certificados, unidades, sync e dashboard

Objetivo: substituir os placeholders por telas reais consumindo a API (Tasks 9/11/16).

## Todo

- [x] **Step 1** — Teste que falha: `CertificadoPage.test.tsx`
      (mostra validade/dias restantes e botão rotacionar respeitando step-up)
- [x] **Step 2** — Rodar `npm test` → FAIL
- [x] **Step 3** — Implementar:
  - `lib/api.ts`: endpoints unidades, certificados, sync-runs, dashboard (+ `ehErroDeStepUp`)
  - `features/certificados/`: `CertificadoPage`, `UnidadeCertificadoRoute` (step-up)
  - `features/unidades/`: `UnidadesPage` + formulário de criação
  - `features/sync/`: `SyncPage` (lista de execuções com filtros)
  - `features/dashboard/`: completa (últimas sync, alertas, valor 30d)
  - `router.tsx`: placeholders trocados (restou só NF-e p/ Task 21)
  - `lib/format.ts`: formatadores (data, valor, CNPJ, status)
- [x] **Step 4** — `npm test` (10 passed) + `npx tsc -b` (limpo) + `npm run build` (140 módulos) → PASS
- [x] **Step 5** — Commit `feat(frontend): certificates, units, sync, dashboard`

## Verificação visual (navegador)

- `/unidades`: lista AGU Central (CNPJ formatado, UF, Homologação, "sem certificado")
- `/unidades/1/certificado`: resolve a unidade, mostra "Nenhum certificado" + botão
- `/sync`: filtro de status + tabela
- `/`: dashboard com indicadores e últimas sync

## Contratos da API (mapeados)

- **Certificado** GET `/api/unidades/{id}/certificado` → `{certificado: {...}|null}`; POST multipart (arquivo, senha) → 201; step-up via 403 `{mfa_step_up_required:true}`
- **Unidades** GET → paginado (50/pág); POST → 201 `{data}`
- **Sync-runs** GET → paginado (per_page cap 100)
- **Dashboard** GET → JSON plano
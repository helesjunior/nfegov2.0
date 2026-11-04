# Task 22 — Deploy, observabilidade e verificação final

Fechamento do projeto: teste fim-a-fim com SEFAZ mockada + documentação de
deploy/operação.

## Todo

- [x] **Step 1** — Teste fim-a-fim
      `backend/tests/Feature/EndToEnd/SyncFlowTest.php`:
      cria tenant/unidade/certificado (fixture `cert_e_cnpj.pfx` via rota real de
      upload) → agenda sync pelo `DispatchSyncNfeJob` (mesmo caminho do
      scheduler) → SEFAZ mockada devolve `resNFe` → ciência automática →
      download do XML completo → importação → verificação via API.
- [x] **Step 2** — rodar `--filter=SyncFlowTest` → FAIL → ajustes → PASS
      (1 passed, 25 assertions)

      **Ajuste real:** o mock de `manifestarCiencia`/`baixarPorChave` usava
      `->with($unidade, ...)`, que compara por identidade de objeto. O job
      recarrega a `Unidade` (`withoutGlobalScopes()->find()`), então a instância
      difere. Trocado por `withArgs(fn ($u, $c) => $u->id === ... )` — mesmo
      padrão já usado para `distDFe` no `DistDfeTest`.

- [x] **Step 3** — Documentar deploy e operação no `README.md`:
      escopo fiscal, serviços, operação (sync, freio anti-bloqueio SEFAZ,
      manifestação, certificado, filas, exportações, XMLs), tabela completa de
      variáveis `NFEGOV_*`, testes e referência da API.
      `.env.example` reescrito para a stack real (pgsql/redis/Horizon) com todas
      as variáveis `NFEGOV_*`.

- [x] **Step 4** — Suíte completa:
      - Backend: **114 passed (338 assertions)**
      - Frontend: **13 passed**; `tsc` limpo; build OK (143 módulos)
- [x] **Step 5** — Commit `chore: deploy docs and e2e sync test`
- [x] **Step 6** — Push

## Observações

- O `docker-compose.yml` já trazia os serviços `horizon` e `scheduler`
  (comandos `artisan horizon` e `artisan schedule:work`) — nada a alterar.
- O agendamento (`SyncNfe` a cada 3h em dias úteis) já estava em
  `routes/console.php`; documentado no README.
- O teste e2e cobre o único gap que faltava na verificação: as peças
  (job → guard → service → importer → API) provadas **juntas**, e não apenas
  isoladas como nos testes de unidade.
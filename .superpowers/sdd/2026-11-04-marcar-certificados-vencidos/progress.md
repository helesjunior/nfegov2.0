# SDD ledger — plan: docs/superpowers/plans/2026-11-04-marcar-certificados-vencidos.md

Pre-flight: tasks share the Certificate model interface (Task 1 produces `expirado_em`/`foiExpirado()`; Tasks 2 and 3 consume them). Consistent.

Task 1: complete (commits 04fa726..d08cafe, tests: php artisan test --filter=CertificateTest → 2 passed, 4 assertions)
Task 2: complete (commits d08cafe..91420df, tests: php artisan test --filter=MarkExpiredCertificatesTest → 5 passed, 10 assertions)
Task 3: complete (commits 91420df..49eea50, tests: php artisan test --filter="DashboardCertificateTest|UploadTest" → 12 passed, 41 assertions)
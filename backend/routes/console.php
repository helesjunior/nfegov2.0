<?php

use App\Console\Commands\CheckCertificateExpiry;
use App\Console\Commands\MarkExpiredCertificates;
use App\Console\Commands\SyncNfe;
use App\Console\Commands\SyncNfse;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verificação diária dos certificados e-CNPJ (alertas por e-mail).
Schedule::command(CheckCertificateExpiry::class)->dailyAt('07:00');

// Marca explicitamente os certificados vencidos (carimbo `expirado_em`),
// sem desativá-los. Roda logo após os alertas, para que estes ainda
// reflitam o estado "vencendo" antes do carimbo.
Schedule::command(MarkExpiredCertificates::class)->dailyAt('07:05');

// Sincronização do distDFe a cada `NFEGOV_SYNC_INTERVAL_HOURS` horas (padrão 3),
// só em dias úteis se `NFEGOV_SYNC_WEEKDAYS_ONLY`. As unidades são escalonadas
// dentro do intervalo, e a regra de 1 hora da SEFAZ (fila vazia) é respeitada
// por unidade no `SyncNfeJob`.
$intervaloNfe = max(1, min(23, (int) config('nfegov.sync.interval_hours', 3)));
$syncNfe = Schedule::command(SyncNfe::class)
    ->cron("0 */{$intervaloNfe} * * *")
    ->withoutOverlapping();

if (config('nfegov.sync.weekdays_only', true)) {
    $syncNfe->weekdays();
}

// Recepção de NFS-e pelo ADN, mesma cadência da NF-e. Fila e freio próprios.
Schedule::command(SyncNfse::class)->everyThreeHours()->weekdays()->withoutOverlapping();

<?php

use App\Console\Commands\CheckCertificateExpiry;
use App\Console\Commands\SyncNfe;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verificação diária dos certificados e-CNPJ (alertas por e-mail).
Schedule::command(CheckCertificateExpiry::class)->dailyAt('07:00');

// Sincronização do distDFe a cada 3 horas, em dias úteis. O escalonamento das
// unidades acontece dentro do job, na janela configurada (padrão 3h).
Schedule::command(SyncNfe::class)->everyThreeHours()->weekdays()->withoutOverlapping();
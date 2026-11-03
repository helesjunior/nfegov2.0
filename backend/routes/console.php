<?php

use App\Console\Commands\CheckCertificateExpiry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verificação diária dos certificados e-CNPJ (alertas por e-mail).
Schedule::command(CheckCertificateExpiry::class)->dailyAt('07:00');
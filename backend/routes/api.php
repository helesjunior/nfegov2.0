<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NfeController;
use App\Http\Controllers\Api\SyncRunController;
use App\Http\Controllers\Api\UnidadeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Autenticação da SPA (Sanctum, cookie same-origin) e demais rotas da API.
|
| Todo o consumo é feito pela SPA no MESMO domínio, então a API opera com
| sessão/cookie (grupo `web`): isso garante CSRF + sessão iniciada para o
| fluxo stateful do Sanctum.
|
| Camadas de proteção (nesta ordem):
| - `auth:sanctum` : usuário autenticado por sessão.
| - `tenant`       : define o tenant ativo (global scope `BelongsToTenant`).
| - `mfa`          : papéis administrativos precisam ter o TOTP confirmado.
| - `mfa.recent`   : step-up — exige verificação recente (operações sensíveis).
|
| A ordem importa: `tenant` precisa vir DEPOIS de `auth:sanctum` (usa o
| usuário logado) e ANTES de qualquer consulta a recurso do tenant.
|
*/

Route::middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');

    // Sessão e configuração do MFA (sem `mfa`, para permitir a configuração).
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

        Route::prefix('mfa')->group(function () {
            Route::post('/setup', [MfaController::class, 'setup'])->name('api.mfa.setup');
            Route::post('/confirm', [MfaController::class, 'confirm'])->name('api.mfa.confirm');
            Route::post('/verify', [MfaController::class, 'verify'])->name('api.mfa.verify');
        });
    });

    // Área de NEGÓCIO: exige MFA confirmado para papéis administrativos.
    Route::middleware(['auth:sanctum', 'tenant', 'mfa'])->group(function () {
        Route::get('/unidades/{unidade}/certificado', [CertificateController::class, 'show'])
            ->name('api.unidades.certificado.show');

        // NF-e: listagem, detalhe, XML (assinado) e DANFE.
        Route::get('/nfes', [NfeController::class, 'index'])->name('api.nfes.index');
        Route::get('/nfes/{chave}', [NfeController::class, 'show'])->name('api.nfes.show');
        Route::get('/nfes/{chave}/xml', [NfeController::class, 'xml'])
            ->middleware('signed')
            ->name('api.nfes.xml');
        Route::get('/nfes/{chave}/danfe', [NfeController::class, 'danfe'])->name('api.nfes.danfe');

        // Unidades gestoras.
        Route::get('/unidades', [UnidadeController::class, 'index'])->name('api.unidades.index');
        Route::post('/unidades', [UnidadeController::class, 'store'])->name('api.unidades.store');

        // Sincronizações e painel.
        Route::get('/sync-runs', [SyncRunController::class, 'index'])->name('api.sync-runs.index');
        Route::get('/dashboard', DashboardController::class)->name('api.dashboard');
    });

    // Operações SENSÍVEIS: exigem MFA recente (step-up).
    Route::middleware(['auth:sanctum', 'tenant', 'mfa', 'mfa.recent'])->group(function () {
        Route::post('/unidades/{unidade}/certificado', [CertificateController::class, 'store'])
            ->name('api.unidades.certificado.store');
    });
});
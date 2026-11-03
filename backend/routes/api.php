<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MfaController;
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
| Camadas de proteção:
| - `auth:sanctum`  : usuário autenticado por sessão.
| - `mfa`           : papéis administrativos precisam ter o TOTP confirmado.
| - `mfa.recent`    : step-up — exige verificação recente (operações sensíveis).
|
| Nota: as rotas de SESSÃO (`/me`, `/logout`) e de CONFIGURAÇÃO do MFA ficam
| sem o `mfa`, para que o SPA consiga detectar que o segundo fator está
| pendente (`/me` devolve `mfa_pendente`) e conduzir o usuário à configuração.
| O `mfa` protege as rotas de NEGÓCIO.
|
*/

Route::middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');

    Route::middleware('auth:sanctum')->group(function () {
        // Sessão do usuário (informa se o MFA está pendente).
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');

        // Configuração/verificação do MFA (acessível com login, sem `mfa`).
        Route::prefix('mfa')->group(function () {
            Route::post('/setup', [MfaController::class, 'setup'])->name('api.mfa.setup');
            Route::post('/confirm', [MfaController::class, 'confirm'])->name('api.mfa.confirm');
            Route::post('/verify', [MfaController::class, 'verify'])->name('api.mfa.verify');
        });
    });

    // Área de NEGÓCIO: exige MFA confirmado para papéis administrativos.
    // As rotas concretas (NF-e, unidades, certificados) são adicionadas nas
    // próximas tarefas dentro deste grupo.
    Route::middleware(['auth:sanctum', 'mfa'])->group(function () {
        //
    });
});
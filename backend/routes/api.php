<?php

use App\Http\Controllers\Api\AuthController;
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
*/

Route::middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    });
});
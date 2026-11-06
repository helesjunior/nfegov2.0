<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CertificateController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ExportDownloadController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NfeController;
use App\Http\Controllers\Api\NfeRecuperacaoController;
use App\Http\Controllers\Api\NotificationController;
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

        Route::post('/sessao/tenant', [\App\Http\Controllers\Api\SessaoTenantController::class, 'update'])
            ->name('api.sessao.tenant');

        // Área de perfil do próprio usuário ("Minha conta"). Sem o middleware
        // `mfa`: quem ainda não confirmou o segundo fator precisa acessar a
        // própria área para configurá-lo.
        Route::put('/me', [\App\Http\Controllers\Api\ProfileController::class, 'update'])
            ->name('api.me.update');
        Route::put('/me/password', [\App\Http\Controllers\Api\ProfileController::class, 'updatePassword'])
            ->name('api.me.password');
        Route::delete('/me/mfa', [\App\Http\Controllers\Api\ProfileController::class, 'destroyMfa'])
            ->name('api.me.mfa.destroy');

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

        // Exportações assíncronas (CSV/XLSX e ZIP de XMLs).
        Route::post('/nfes/export', [ExportController::class, 'store'])->name('api.nfes.export');
        Route::post('/nfes/export-xml', [ExportController::class, 'xml'])->name('api.nfes.export-xml');

        // Recuperação manual de NF-e (por chave ou NSU). Exige `sync.executar`;
        // a rota fica ANTES de `/nfes/{chave}` para não colidir com o binding.
        Route::post('/nfes/recuperar', [NfeRecuperacaoController::class, 'store'])
            ->name('api.nfes.recuperar');

        // Unidades gestoras.
        Route::get('/unidades', [UnidadeController::class, 'index'])->name('api.unidades.index');
        Route::post('/unidades', [UnidadeController::class, 'store'])->name('api.unidades.store');
        Route::get('/unidades/{unidade}', [UnidadeController::class, 'show'])->name('api.unidades.show');
        Route::put('/unidades/{unidade}', [UnidadeController::class, 'update'])->name('api.unidades.update');
        Route::put('/unidades/{unidade}/ativo', [UnidadeController::class, 'ativo'])->name('api.unidades.ativo');

        // Sincronizações e painel.
        Route::get('/sync-runs', [SyncRunController::class, 'index'])->name('api.sync-runs.index');
        Route::get('/dashboard', DashboardController::class)->name('api.dashboard');

        // Notificações do usuário (ex.: exportações prontas) e download do
        // arquivo gerado. O download usa URL assinada e temporária.
        Route::get('/notifications', [NotificationController::class, 'index'])
            ->name('api.notifications.index');
        Route::post('/notifications/{id}/lida', [NotificationController::class, 'marcarComoLida'])
            ->name('api.notifications.lida');
        Route::get('/exports/{arquivo}', ExportDownloadController::class)
            ->middleware('signed')
            ->name('api.exports.download');

        // Usuários (gestão). Escopo N:N resolvido no controller/policy.
        Route::get('/usuarios', [\App\Http\Controllers\Api\UserController::class, 'index'])->name('api.usuarios.index');
        Route::post('/usuarios', [\App\Http\Controllers\Api\UserController::class, 'store'])->name('api.usuarios.store');
        Route::put('/usuarios/{usuario}', [\App\Http\Controllers\Api\UserController::class, 'update'])->name('api.usuarios.update');
        Route::put('/usuarios/{usuario}/ativo', [\App\Http\Controllers\Api\UserController::class, 'ativo'])->name('api.usuarios.ativo');
    });

    // Operações SENSÍVEIS: exigem MFA recente (step-up).
    Route::middleware(['auth:sanctum', 'tenant', 'mfa', 'mfa.recent'])->group(function () {
        Route::post('/unidades/{unidade}/certificado', [CertificateController::class, 'store'])
            ->name('api.unidades.certificado.store');
    });

    // Administração de plataforma: Entidades (só super-admin, MFA confirmado).
    Route::middleware(['auth:sanctum', 'mfa'])->group(function () {
        Route::get('/tenants', [\App\Http\Controllers\Api\TenantController::class, 'index'])->name('api.tenants.index');
        Route::get('/tenants/{tenant}', [\App\Http\Controllers\Api\TenantController::class, 'show'])->name('api.tenants.show');
    });

    // Mutações de plataforma: exigem MFA RECENTE (step-up), conforme o design.
    Route::middleware(['auth:sanctum', 'mfa', 'mfa.recent'])->group(function () {
        Route::post('/tenants', [\App\Http\Controllers\Api\TenantController::class, 'store'])->name('api.tenants.store');
        Route::put('/tenants/{tenant}', [\App\Http\Controllers\Api\TenantController::class, 'update'])->name('api.tenants.update');
        Route::put('/tenants/{tenant}/ativo', [\App\Http\Controllers\Api\TenantController::class, 'ativo'])->name('api.tenants.ativo');
    });
});
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SPA no mesmo domínio: Sanctum autentica por cookie de sessão.
        $middleware->statefulApi();

        $middleware->alias([
            'mfa' => \App\Http\Middleware\RequireMfa::class,
            'mfa.recent' => \App\Http\Middleware\RequireRecentMfa::class,
            'tenant' => \App\Http\Middleware\SetTenantContext::class,
        ]);

        // Seta o tenant DEPOIS da autenticação (prioridade logo após o
        // `Authenticate`), para que `$request->user()` já esteja resolvido.
        $middleware->appendToGroup('api', \App\Http\Middleware\SetTenantContext::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\SetTenantContext::class);

        $middleware->priority([
            \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Routing\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \App\Http\Middleware\SetTenantContext::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

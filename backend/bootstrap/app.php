<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })
    ->booted(function (): void {
        // Limite global de consultas distDFe (protege o IP compartilhado).
        RateLimiter::for('sefaz-distdfe', function (Request $request) {
            $porMinuto = (int) config('nfegov.sefaz.rate_per_minute', 30);

            return Limit::perMinute($porMinuto)->by('sefaz-distdfe-global');
        });
    })->create();

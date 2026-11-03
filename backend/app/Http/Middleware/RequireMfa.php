<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige MFA confirmado para papéis administrativos.
 *
 * `super-admin` e `admin-tenant` só navegam depois de confirmar o TOTP.
 * Usuários sem papel administrativo (operador/consulta) não são afetados.
 *
 * Exceção: as próprias rotas de MFA (`mfa/*`) ficam fora deste middleware,
 * para que o usuário consiga configurar/confirmar o segundo fator.
 */
class RequireMfa
{
    public function __construct(private readonly \App\Services\TwoFactorService $twoFactor)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $this->exigeMfa($user)) {
            return $next($request);
        }

        if (! $this->twoFactor->confirmado($user)) {
            return response()->json([
                'message' => 'É necessário configurar o segundo fator (MFA) para continuar.',
                'mfa_required' => true,
            ], 403);
        }

        return $next($request);
    }

    /**
     * O usuário tem algum papel que obriga MFA?
     */
    private function exigeMfa(User $user): bool
    {
        return $user->hasAnyRole(config('nfegov.mfa.papeis_obrigatorios', ['super-admin', 'admin-tenant']));
    }
}
<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Step-up: exige que o MFA tenha sido verificado há pouco tempo.
 *
 * Usado em operações sensíveis (ex.: rotação de certificado e-CNPJ). O
 * usuário precisa reenviar um código TOTP válido em `/api/mfa/verify` para
 * renovar a janela (`mfa_recent_at`).
 *
 * A janela é configurável em `nfegov.mfa.step_up_minutos` (padrão: 15 min).
 */
class RequireRecentMfa
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $minutos = (int) config('nfegov.mfa.step_up_minutos', 15);

        if (! $this->twoFactor->recente($user, $minutos)) {
            return response()->json([
                'message' => 'Esta operação exige uma nova verificação de segurança (MFA).',
                'mfa_step_up_required' => true,
            ], 403);
        }

        return $next($request);
    }
}
<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige o segundo fator (TOTP) na área de negócio.
 *
 * - `super-admin` e `admin-tenant` só navegam depois de configurar o TOTP.
 * - Quem tem MFA ativo (obrigatório ou opcional) precisa informar o código
 *   a cada login: a verificação fica registrada na sessão.
 * - Operador/consulta sem MFA configurado não são afetados.
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

        if (! $this->twoFactor->confirmado($user)) {
            // Papel administrativo sem MFA configurado: precisa configurar.
            if ($this->exigeMfa($user)) {
                return response()->json([
                    'message' => 'É necessário configurar o segundo fator (MFA) para continuar.',
                    'mfa_required' => true,
                ], 403);
            }

            return $next($request);
        }

        // MFA ativo: a senha sozinha não basta. O código precisa ter sido
        // informado nesta sessão de login.
        if (! $this->twoFactor->verificadoNaSessao($user)) {
            return response()->json([
                'message' => 'Informe o código do segundo fator (MFA) para continuar.',
                'mfa_verificacao_required' => true,
            ], 403);
        }

        return $next($request);
    }

    private function exigeMfa(User $user): bool
    {
        return $user->hasAnyRole(config('nfegov.mfa.papeis_obrigatorios', ['super-admin', 'admin-tenant']));
    }
}
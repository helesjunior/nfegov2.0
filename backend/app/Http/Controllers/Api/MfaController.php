<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * MFA (TOTP) da SPA.
 *
 * Fluxo:
 * 1. `POST /api/mfa/setup`   — gera o segredo e devolve a URL otpauth (QR).
 * 2. `POST /api/mfa/confirm` — valida um código e confirma o segundo fator.
 * 3. `POST /api/mfa/verify`  — revalida um código e renova a janela de step-up
 *                              (usado antes de operações sensíveis).
 *
 * O segredo SÓ é devolvido no setup, enquanto o MFA ainda não foi confirmado.
 */
class MfaController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    /**
     * Gera (ou regenera) o segredo do usuário. Ainda não fica confirmado.
     */
    public function setup(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->twoFactor->confirmado($user)) {
            return response()->json([
                'message' => 'O MFA já está configurado para este usuário.',
            ], 409);
        }

        $secret = $this->twoFactor->gerarSegredo();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'qr_url' => $this->twoFactor->qrUrl($user, $secret),
        ]);
    }

    /**
     * Confirma o MFA com um código válido; a partir daí, exige o segundo fator.
     */
    public function confirm(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'code' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (blank($user->two_factor_secret)) {
            throw ValidationException::withMessages([
                'code' => 'Inicie a configuração do MFA antes de confirmar.',
            ]);
        }

        if (! $this->twoFactor->verify($user, $dados['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Código inválido. Verifique o relógio do seu dispositivo.',
            ]);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->twoFactor->marcarRecente($user);
        $this->twoFactor->marcarVerificadoNaSessao($user);

        return response()->json(['message' => 'MFA confirmado com sucesso.']);
    }

    /**
     * Revalida o código e renova a janela de step-up (`mfa_recent_at`).
     */
    public function verify(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'code' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->confirmado($user)) {
            throw ValidationException::withMessages([
                'code' => 'O MFA ainda não foi confirmado para este usuário.',
            ]);
        }

        if (! $this->twoFactor->verify($user, $dados['code'])) {
            throw ValidationException::withMessages([
                'code' => 'Código inválido.',
            ]);
        }

        $this->twoFactor->marcarRecente($user);
        $this->twoFactor->marcarVerificadoNaSessao($user);

        return response()->json([
            'message' => 'Verificação de segurança renovada.',
            'recente_ate' => $user->fresh()->mfa_recent_at,
        ]);
    }
}
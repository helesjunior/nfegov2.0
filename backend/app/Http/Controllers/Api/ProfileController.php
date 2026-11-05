<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisableMfaRequest;
use App\Http\Requests\UpdatePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Services\TwoFactorService;
use App\Services\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Área de perfil do usuário logado ("Minha conta").
 *
 * Fica no grupo de sessão (sem o middleware `mfa`) para que quem ainda não
 * confirmou o segundo fator consiga acessar a própria área.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly UserPresenter $presenter)
    {
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only(['name', 'email']))->save();

        return response()->json(['user' => $this->presenter->paraUsuario($user->fresh(), $this->preferencia($request))]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        // O cast `password => hashed` do model aplica o hash ao atribuir.
        $user->fill(['password' => $request->validated('password')])->save();

        return response()->json(['user' => $this->presenter->paraUsuario($user->fresh(), $this->preferencia($request))]);
    }

    public function destroyMfa(DisableMfaRequest $request, TwoFactorService $twoFactor): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->hasAnyRole(config('nfegov.mfa.papeis_obrigatorios', []))) {
            abort(403, 'Seu perfil exige MFA; não é possível desabilitar.');
        }

        if (! $twoFactor->confirmado($user)) {
            throw ValidationException::withMessages([
                'code' => 'O MFA não está habilitado.',
            ]);
        }

        if (! $twoFactor->verify($user, $request->validated('code'))) {
            throw ValidationException::withMessages([
                'code' => 'Código inválido.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'mfa_recent_at' => null,
        ])->save();

        return response()->json(['user' => $this->presenter->paraUsuario($user->fresh(), $this->preferencia($request))]);
    }

    /**
     * Tenant ativo preferido na sessão (ou null = "Todas"/sem preferência).
     */
    private function preferencia(Request $request): ?int
    {
        $valor = $request->session()?->get('tenant_id');

        return $valor === null ? null : (int) $valor;
    }
}
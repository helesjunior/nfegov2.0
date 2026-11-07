<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Services\UserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Autenticação da SPA (Sanctum, cookie same-origin).
 *
 * A sessão é mantida por cookie httpOnly; o SPA apenas consome /api/login,
 * /api/me e /api/logout. Proteção contra força bruta via rate limiter.
 */
class AuthController extends Controller
{
    public function __construct(private readonly UserPresenter $presenter)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $credenciais = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $chave = 'login:' . strtolower($credenciais['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($chave, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Muitas tentativas. Tente novamente em '
                    . RateLimiter::availableIn($chave) . ' segundos.',
            ]);
        }

        if (! Auth::attempt($credenciais, $request->boolean('remember'))) {
            RateLimiter::hit($chave, 60);

            throw ValidationException::withMessages([
                'email' => 'Credenciais inválidas.',
            ]);
        }

        RateLimiter::clear($chave);
        $request->session()->regenerate();

        // Login novo: o segundo fator ainda não foi informado nesta sessão.
        $request->session()->forget(TwoFactorService::SESSAO_VERIFICADO);

        /** @var User $user */
        $user = Auth::user();

        if (! (bool) ($user->ativo ?? true)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            Auth::forgetGuards();

            throw ValidationException::withMessages([
                'email' => 'Usuário inativo. Procure o administrador.',
            ]);
        }

        return response()->json([
            'user' => $this->presenter->paraUsuario($user, $this->preferenciaTenant()),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->presenter->paraUsuario($user, $this->preferenciaTenant()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Descarta instâncias de guard já resolvidas (evita que o guard
        // `sanctum` continue "autenticado" no mesmo request/ciclo de teste).
        Auth::forgetGuards();

        return response()->json(['message' => 'Sessão encerrada.']);
    }

    /**
     * Tenant ativo preferido na sessão (ou null = "Todas"/sem preferência).
     */
    private function preferenciaTenant(): ?int
    {
        $valor = request()->session()?->get('tenant_id');

        return $valor === null ? null : (int) $valor;
    }
}
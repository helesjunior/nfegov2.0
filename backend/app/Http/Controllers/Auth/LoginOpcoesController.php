<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\GoogleLogin;
use Illuminate\Http\JsonResponse;

/**
 * Opções de login disponíveis (PÚBLICO: a tela de login precisa saber, antes
 * de autenticar, se mostra o botão "Entrar com Google").
 *
 * Só expõe booleanos; nunca credenciais nem a lista de domínios.
 */
class LoginOpcoesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'google' => GoogleLogin::disponivel(),
            ],
        ]);
    }
}

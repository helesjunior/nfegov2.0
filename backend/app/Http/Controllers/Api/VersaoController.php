<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Versão da aplicação (rodapé do menu).
 *
 * A fonte de verdade é o arquivo `VERSION` na raiz do repositório
 * (`config('nfegov.versao.numero')`). O timestamp reflete a build (arquivo
 * `build.json`, gravado pelo script de build) — nunca o horário do request.
 *
 * Endpoint PÚBLICO (sem autenticação): a versão não é dado sensível e o
 * rodapé deve aparecer mesmo antes do login.
 */
class VersaoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $versao = (string) config('nfegov.versao.numero', '0.0.0');
        $buildAt = config('nfegov.versao.build_at');

        // Rótulo pronto para exibição (o frontend não precisa montar o formato).
        $rotulo = $buildAt !== null
            ? sprintf('%s-%s', $versao, $buildAt)
            : $versao;

        return response()->json([
            'data' => [
                'versao' => $versao,
                'build_at' => $buildAt,
                'rotulo' => $rotulo,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Notificações do usuário autenticado (canal `database`).
 *
 * Hoje serve sobretudo às exportações assíncronas: o job grava o arquivo e
 * notifica; aqui o usuário lista as notificações e obtém, para cada
 * exportação pronta, uma URL ASSINADA e temporária de download.
 *
 * A URL é gerada sob demanda (expira em poucos minutos) em vez de ser guardada
 * na notificação — assim o link nunca fica permanente.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $notificacoes = $usuario->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(function ($n) {
                $dados = $n->data;

                return [
                    'id' => $n->id,
                    'lida' => $n->read_at !== null,
                    'criada_em' => $n->created_at?->toIso8601String(),
                    'dados' => $dados,
                    // Link de download só para exportações (`caminho` presente).
                    'url_download' => $this->urlDeDownload($dados['caminho'] ?? null),
                ];
            });

        return response()->json([
            'data' => $notificacoes,
            'nao_lidas' => $usuario->unreadNotifications()->count(),
        ]);
    }

    public function marcarComoLida(Request $request, string $id): JsonResponse
    {
        $notificacao = $request->user()->notifications()->whereKey($id)->firstOrFail();

        $notificacao->markAsRead();

        return response()->json(['message' => 'Notificação marcada como lida.']);
    }

    /**
     * Gera a URL assinada de download a partir do caminho no disco privado.
     *
     * O caminho tem o formato `exports/{tenant_id}/{arquivo}`. O tenant vai
     * junto (coberto pela assinatura) e o controller de download confere se o
     * usuário tem acesso a ele.
     */
    private function urlDeDownload(?string $caminho): ?string
    {
        if (! $caminho) {
            return null;
        }

        $arquivo = basename($caminho);
        $tenant = basename(dirname($caminho));

        $parametros = ['arquivo' => $arquivo];
        if (ctype_digit($tenant)) {
            $parametros['tenant'] = (int) $tenant;
        }

        return URL::temporarySignedRoute('api.exports.download', now()->addMinutes(30), $parametros);
    }
}
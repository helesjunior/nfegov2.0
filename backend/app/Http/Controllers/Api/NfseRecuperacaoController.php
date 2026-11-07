<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecuperarNfseRequest;
use App\Jobs\RecuperarNfseJob;
use App\Models\Concerns\Auditable;
use App\Models\Unidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Busca manual de NFS-e por chave (`POST /api/nfses/recuperar`).
 *
 * Assíncrona (fila `adn`): o usuário é notificado ao final. Exige
 * `sync.executar`, como a busca manual de NF-e. Um pedido idêntico enquanto o
 * anterior está na fila recebe 409, para não repetir a consulta ao ADN.
 */
class NfseRecuperacaoController extends Controller
{
    use Auditable;

    public function store(RecuperarNfseRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->can('sync.executar') || $user->hasRole('super-admin'),
            403,
            'Você não tem permissão para executar sincronizações.',
        );

        // Unidade escopada ao tenant ativo (404 se não pertencer).
        $unidade = Unidade::findOrFail($request->integer('unidade_id'));

        Gate::authorize('view', $unidade);

        $chave = (string) $request->validated('chave');
        $job = new RecuperarNfseJob($unidade->id, $user->id, $chave);

        if (! Cache::add($job->chaveIdempotencia(), true, $job->uniqueFor)) {
            return response()->json([
                'message' => 'Já existe uma busca igual em andamento. Aguarde a conclusão.',
            ], 409);
        }

        dispatch($job);

        $this->audit('nfse.recuperacao.solicitada', ['unidade_id' => $unidade->id, 'chave' => $chave], 'unidade', $unidade->id, $user, $request);

        return response()->json([
            'message' => 'Busca agendada. Você será notificado quando terminar.',
        ], 202);
    }
}

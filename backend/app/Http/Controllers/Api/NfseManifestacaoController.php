<?php

namespace App\Http\Controllers\Api;

use App\Domain\Nfse\ManifestacaoNfse;
use App\Domain\Nfse\ManifestacaoNfseException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ManifestarNfseRequest;
use App\Http\Resources\NfseResource;
use App\Models\Concerns\Auditable;
use App\Models\Nfse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Manifestação do tomador sobre uma NFS-e (`POST /api/nfses/{chave}/manifestacao`).
 *
 * É um ato fiscal registrado na SEFIN Nacional, por isso a rota exige MFA
 * recente (step-up), a permissão `nfe.export` sobre a nota, e cada pedido fica
 * na auditoria com quem, qual nota e qual decisão. Nunca repete sozinho.
 */
class NfseManifestacaoController extends Controller
{
    use Auditable;

    public function store(ManifestarNfseRequest $request, string $chave, ManifestacaoNfse $manifestacao): JsonResponse
    {
        $nfse = Nfse::where('chave', $chave)
            ->where('unidade_id', $request->integer('unidade_id'))
            ->firstOrFail();

        Gate::authorize('manifestar', $nfse);

        $dados = $request->validated();
        $rejeitar = $dados['acao'] === 'rejeitar';

        try {
            $nfse = $rejeitar
                ? $manifestacao->rejeitar($nfse, (int) $dados['motivo'], $dados['descricao'] ?? null)
                : $manifestacao->confirmar($nfse);
        } catch (ManifestacaoNfseException $e) {
            $this->audit('nfse.manifestacao.recusada', [
                'acao' => $dados['acao'], 'motivo' => $dados['motivo'] ?? null, 'erro' => $e->getMessage(),
            ], 'nfse', $nfse->chave, $request->user(), $request);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Não foi possível falar com a SEFIN Nacional. A manifestação não foi registrada; tente novamente.',
            ], 502);
        }

        $this->audit('nfse.manifestacao.registrada', [
            'acao' => $dados['acao'], 'motivo' => $dados['motivo'] ?? null, 'descricao' => $dados['descricao'] ?? null,
        ], 'nfse', $nfse->chave, $request->user(), $request);

        return (new NfseResource($nfse->load('unidade')))->response();
    }
}

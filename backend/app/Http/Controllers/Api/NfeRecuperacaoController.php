<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecuperarNfeRequest;
use App\Jobs\RecuperarNfeJob;
use App\Models\Concerns\Auditable;
use App\Models\Unidade;
use Illuminate\Bus\UniqueLock;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Recuperação manual de NF-e (`POST /api/nfes/recuperar`).
 *
 * Permite buscar uma nota que já passou pela fila do DistDFe, por chave de
 * acesso (`consChNFe`) ou por NSU específico (`consNSU`). O trabalho é
 * assíncrono (fila `sefaz`) e o usuário é notificado ao final.
 *
 * Autorização: exige `sync.executar` — a busca pode enviar a Ciência da
 * Operação (irreversível), por isso o papel somente-leitura (`consulta`) é
 * barrado com 403. O escopo da unidade é validado pela policy (404 se não
 * pertencer ao tenant, sem revelar a existência).
 *
 * Idempotência: o job é `ShouldBeUnique`; um pedido idêntico enquanto o
 * anterior está na fila recebe 409 (não enfileira uma segunda consulta, o que
 * agravaria o consumo na SEFAZ).
 */
class NfeRecuperacaoController extends Controller
{
    use Auditable;

    public function store(RecuperarNfeRequest $request): JsonResponse
    {
        $user = $request->user();

        // Permissão de execução: quem só lê NF-e (`nfe.view`) não dispara busca.
        abort_unless(
            $user->can('sync.executar') || $user->hasRole('super-admin'),
            403,
            'Você não tem permissão para executar sincronizações.',
        );

        // Unidade escopada ao tenant ativo (404 se não pertencer).
        $unidade = Unidade::findOrFail($request->integer('unidade_id'));

        Gate::authorize('view', $unidade);

        $dados = $request->validated();
        $chave = $dados['chave'] ?? null;
        $nsu = isset($dados['nsu']) ? (int) $dados['nsu'] : null;

        $job = new RecuperarNfeJob($unidade->id, $user->id, $chave, $nsu);

        // Pedido idêntico em andamento: 409 em vez de um segundo job. O próprio
        // `ShouldBeUnique` do job é a guarda real; aqui só damos um retorno
        // honesto ao usuário. A chave coincide com a do framework (`UniqueLock`),
        // e a sonda é não-destrutiva: se livre, o lock é liberado na hora e o
        // job re-adquire ao ser enfileirado.
        $lock = Cache::lock(UniqueLock::getKey($job), $job->uniqueFor);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Já existe uma busca igual em andamento. Aguarde a conclusão.',
            ], 409);
        }

        $lock->release();

        dispatch($job);

        $this->audit(
            'nfe.recuperacao.solicitada',
            ['unidade_id' => $unidade->id, 'chave' => $chave, 'nsu' => $nsu],
            'unidade',
            $unidade->id,
            $user,
            $request,
        );

        return response()->json([
            'message' => 'Busca agendada. Você será notificado quando terminar.',
        ], 202);
    }
}
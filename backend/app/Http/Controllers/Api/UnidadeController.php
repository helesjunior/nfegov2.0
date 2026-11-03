<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnidadeRequest;
use App\Http\Resources\UnidadeResource;
use App\Models\Unidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Unidades gestoras: listagem e cadastro.
 *
 * Toda operação respeita o tenant ativo (global scope). O CNPJ é único por
 * tenant (regra de banco + validação no FormRequest).
 */
class UnidadeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Unidade::class);

        return UnidadeResource::collection(
            Unidade::with('certificate')->orderBy('nome')->paginate(50)
        );
    }

    public function store(StoreUnidadeRequest $request): JsonResponse
    {
        Gate::authorize('create', Unidade::class);

        $dados = $request->validated();

        // O tenant_id vem do contexto ativo (middleware `tenant`).
        $unidade = Unidade::create($dados);

        return (new UnidadeResource($unidade))->response()->setStatusCode(201);
    }
}
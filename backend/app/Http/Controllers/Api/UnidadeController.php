<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUnidadeRequest;
use App\Http\Requests\UpdateUnidadeRequest;
use App\Http\Resources\UnidadeResource;
use App\Models\Concerns\Auditable;
use App\Models\Unidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Unidades gestoras: listagem, detalhe, cadastro e edição.
 *
 * Toda operação respeita o tenant ativo (global scope). O CNPJ é único por
 * tenant (regra de banco + validação no FormRequest). A entidade (`tenant_id`)
 * não é editável: unidade não muda de tenant.
 */
class UnidadeController extends Controller
{
    use Auditable;

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

        $this->audit(
            'unidade.criada',
            ['cnpj' => $unidade->cnpj, 'uf' => $unidade->uf],
            'unidade',
            $unidade->id,
            $request->user(),
            $request,
        );

        return (new UnidadeResource($unidade))->response()->setStatusCode(201);
    }

    public function show(int $unidade): UnidadeResource
    {
        $model = Unidade::with('certificate')->findOrFail($unidade);
        Gate::authorize('view', $model);

        return new UnidadeResource($model);
    }

    public function update(UpdateUnidadeRequest $request, int $unidade): UnidadeResource
    {
        $model = Unidade::findOrFail($unidade);
        Gate::authorize('update', $model);

        $model->update($request->validated());

        $this->audit('unidade.atualizada', ['cnpj' => $model->cnpj, 'uf' => $model->uf], 'unidade', $model->id, $request->user(), $request);

        return new UnidadeResource($model);
    }

    public function ativo(Request $request, int $unidade): UnidadeResource
    {
        $model = Unidade::findOrFail($unidade);
        Gate::authorize('update', $model);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);
        $model->update(['ativo' => $dados['ativo']]);

        $this->audit('unidade.ativo.alterado', ['ativo' => $model->ativo], 'unidade', $model->id, $request->user(), $request);

        return new UnidadeResource($model);
    }
}
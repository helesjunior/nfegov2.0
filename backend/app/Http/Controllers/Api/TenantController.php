<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTenantRequest;
use App\Http\Requests\UpdateTenantRequest;
use App\Http\Resources\TenantResource;
use App\Models\Concerns\Auditable;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * CRUD de Entidades (Tenant). Somente super-admin.
 */
class TenantController extends Controller
{
    use Auditable;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Tenant::class);

        // `per_page` limitado a 100 (mesmo teto de outras listagens); o padrão
        // é 50. A SPA pede uma página maior para montar o seletor de entidades.
        $perPage = min((int) $request->integer('per_page', 50) ?: 50, 100);

        return TenantResource::collection(Tenant::orderBy('nome')->paginate($perPage));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        Gate::authorize('create', Tenant::class);

        $tenant = Tenant::create($request->validated());

        $this->audit('tenant.criado', ['nome' => $tenant->nome], 'tenant', $tenant->id, $request->user(), $request);

        return (new TenantResource($tenant))->response()->setStatusCode(201);
    }

    public function show(Tenant $tenant): TenantResource
    {
        Gate::authorize('view', Tenant::class);

        return new TenantResource($tenant);
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        Gate::authorize('update', Tenant::class);

        $tenant->update($request->validated());

        $this->audit('tenant.atualizado', ['nome' => $tenant->nome], 'tenant', $tenant->id, $request->user(), $request);

        return new TenantResource($tenant);
    }

    public function ativo(Request $request, Tenant $tenant): TenantResource
    {
        Gate::authorize('update', Tenant::class);

        $dados = $request->validate(['ativo' => ['required', 'boolean']]);
        $tenant->update(['ativo' => $dados['ativo']]);

        $this->audit('tenant.ativo.alterado', ['ativo' => $tenant->ativo], 'tenant', $tenant->id, $request->user(), $request);

        return new TenantResource($tenant);
    }
}
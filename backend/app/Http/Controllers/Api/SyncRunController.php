<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SyncRunResource;
use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Histórico de execuções de sincronização (auditoria técnica).
 */
class SyncRunController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SyncRun::class);

        $perPage = min((int) $request->integer('per_page', 25) ?: 25, 100);

        $query = SyncRun::query()
            ->with('unidade')
            ->when($request->integer('unidade_id'), fn ($q, $u) => $q->where('unidade_id', $u))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when(
                in_array($request->input('tipo'), [SyncRun::TIPO_NFE, SyncRun::TIPO_NFSE], true),
                fn ($q) => $q->where('tipo', $request->input('tipo')),
            )
            ->latest('iniciado_em');

        return SyncRunResource::collection($query->paginate($perPage));
    }
}
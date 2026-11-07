<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Execução de sincronização (auditoria técnica).
 *
 * @mixin \App\Models\SyncRun
 */
class SyncRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'unidade_id' => $this->unidade_id,
            'tipo' => $this->tipo ?? 'nfe',
            'iniciado_em' => $this->iniciado_em?->toIso8601String(),
            'finalizado_em' => $this->finalizado_em?->toIso8601String(),
            'nsu_inicial' => $this->nsu_inicial,
            'nsu_final' => $this->nsu_final,
            'docs_count' => $this->docs_count,
            'status' => $this->status,
            'erro' => $this->erro,
        ];
    }
}
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Item de uma NF-e.
 *
 * @mixin \App\Models\NfeItem
 */
class NfeItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequencial' => $this->sequencial,
            'descricao' => $this->descricao,
            'cfop' => $this->cfop,
            'unidade_medida' => $this->unidade_medida,
            'quantidade' => $this->quantidade,
            'valor_unitario' => $this->valor_unitario,
            'valor_total' => $this->valor_total,
        ];
    }
}
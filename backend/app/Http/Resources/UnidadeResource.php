<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unidade gestora.
 *
 * Expõe dados cadastrais e o status do certificado (metadados apenas;
 * jamais senha ou PFX).
 *
 * @mixin \App\Models\Unidade
 */
class UnidadeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $cert = $this->whenLoaded('certificate', fn () => $this->certificate, null);

        return [
            'id' => $this->id,
            'codigo_unidade' => $this->codigo_unidade,
            'nome' => $this->nome,
            'nome_resumido' => $this->nome_resumido,
            'cnpj' => $this->cnpj,
            'ie' => $this->ie,
            'uf' => $this->uf,
            'tp_amb' => $this->tp_amb,
            'ativo' => (bool) $this->ativo,
            'certificado' => $cert ? [
                'cnpj' => $cert->cnpj,
                'titular' => $cert->titular,
                'valido_ate' => $cert->valido_ate,
                'dias_restantes' => $cert->diasRestantes(),
                'vencido' => $cert->vencido(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
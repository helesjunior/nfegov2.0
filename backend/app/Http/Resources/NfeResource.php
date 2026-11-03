<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representação de uma NF-e na API.
 *
 * Expõe apenas metadados. O XML nunca é embutido: quando o usuário tem
 * permissão de exportação, devolvemos `xml_url` (URL assinada e temporária).
 *
 * @mixin \App\Models\Nfe
 */
class NfeResource extends JsonResource
{
    /**
     * URL assinada do XML, definida pelo controller (fora do escopo da lista).
     */
    public ?string $xmlUrl = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chave' => $this->chave,
            'numero' => $this->numero,
            'serie' => $this->serie,
            'modelo' => $this->modelo,
            'unidade_id' => $this->unidade_id,
            'emitente_cnpj' => $this->emitente_cnpj,
            'emitente_nome' => $this->emitente_nome,
            'dest_cnpj' => $this->dest_cnpj,
            'valor_total' => $this->valor_total,
            'natureza_operacao' => $this->natureza_operacao,
            'data_emissao' => $this->data_emissao?->toIso8601String(),
            'cstat' => $this->cstat,
            'estado' => $this->estado,
            'emitente_proprio' => (bool) $this->emitente_proprio,
            'tem_xml' => $this->xml_path !== null,
            'xml_url' => $this->xmlUrl,
            'itens' => NfeItemResource::collection($this->whenLoaded('itens')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Adiciona a URL assinada/temporária do XML (apenas quando autorizado).
     */
    public function comXmlUrl(?string $url): static
    {
        $this->xmlUrl = $url;

        return $this;
    }
}
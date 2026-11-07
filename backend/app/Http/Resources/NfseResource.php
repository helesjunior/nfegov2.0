<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NFS-e na API. O XML nunca é embutido: com permissão de exportação,
 * `xml_url` traz uma URL assinada e temporária.
 *
 * @mixin \App\Models\Nfse
 */
class NfseResource extends JsonResource
{
    public ?string $xmlUrl = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'chave' => $this->chave,
            'nsu' => $this->nsu,
            'papel' => $this->papel,
            'situacao' => $this->situacao,
            'numero' => $this->numero,
            'serie_dps' => $this->serie_dps,
            'numero_dps' => $this->numero_dps,
            'unidade_id' => $this->unidade_id,
            'unidade' => $this->whenLoaded('unidade', fn () => $this->unidade ? [
                'id' => $this->unidade->id,
                'nome' => $this->unidade->nome,
                'nome_resumido' => $this->unidade->nome_resumido,
                'cnpj' => $this->unidade->cnpj,
            ] : null),
            'municipio_emissor_codigo' => $this->municipio_emissor_codigo,
            'municipio_emissor_nome' => $this->municipio_emissor_nome,
            'municipio_incidencia_nome' => $this->municipio_incidencia_nome,
            'prestador_documento' => $this->prestador_documento,
            'prestador_nome' => $this->prestador_nome,
            'tomador_documento' => $this->tomador_documento,
            'tomador_nome' => $this->tomador_nome,
            'intermediario_documento' => $this->intermediario_documento,
            'intermediario_nome' => $this->intermediario_nome,
            'data_emissao' => $this->data_emissao?->toIso8601String(),
            'data_processamento' => $this->data_processamento?->toIso8601String(),
            'competencia' => $this->competencia?->format('Y-m-d'),
            'codigo_servico' => $this->codigo_servico,
            'servico_nome' => $this->servico_nome,
            'descricao_servico' => $this->descricao_servico,
            'valor_servico' => $this->valor_servico,
            'valor_liquido' => $this->valor_liquido,
            'valor_iss' => $this->valor_iss,
            'iss_retido' => (bool) $this->iss_retido,
            'versao_leiaute' => $this->versao_leiaute,
            'tem_xml' => $this->xml_path !== null,
            'xml_url' => $this->xmlUrl,
            'eventos' => $this->whenLoaded('eventos', fn () => $this->eventos->map(fn ($e) => [
                'id' => $e->id,
                'nsu' => $e->nsu,
                'tipo_evento' => $e->tipo_evento,
                'codigo_evento' => $e->codigo_evento,
                'descricao' => $e->descricao,
                'motivo' => $e->motivo,
                'data_evento' => $e->data_evento?->toIso8601String(),
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function comXmlUrl(?string $url): static
    {
        $this->xmlUrl = $url;

        return $this;
    }
}

<?php

namespace App\Domain\Nfe;

use App\Models\Nfe;
use App\Support\Ordenacao;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrói a consulta de NF-e a partir dos filtros da API.
 *
 * Centraliza a montagem para que a listagem paginada e as exportações
 * (CSV/XLSX/ZIP) apliquem EXATAMENTE os mesmos filtros. O escopo de tenant
 * vem do global scope (`BelongsToTenant`).
 */
class NfeQuery
{
    /**
     * Ordenações aceitas em `?ordem=` (lista branca): chave => [coluna, tipo].
     */
    public const ORDENACOES = [
        'emissao' => ['data_emissao', Ordenacao::NATURAL],
        'emitente' => ['emitente_nome', Ordenacao::TEXTO],
        'numero' => ['numero', Ordenacao::NUMERO],
        'valor' => ['valor_total', Ordenacao::NATURAL],
    ];

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function build(array $filtros): Builder
    {
        return Nfe::query()
            ->when($filtros['de'] ?? null, fn (Builder $q, $de) => $q->where('data_emissao', '>=', $de.' 00:00:00'))
            ->when($filtros['ate'] ?? null, fn (Builder $q, $ate) => $q->where('data_emissao', '<=', $ate.' 23:59:59'))
            ->when($filtros['unidade_id'] ?? null, fn (Builder $q, $u) => $q->where('unidade_id', $u))
            ->when($filtros['emitente_cnpj'] ?? null, fn (Builder $q, $c) => $q->where('emitente_cnpj', $c))
            ->when($filtros['valor_min'] ?? null, fn (Builder $q, $v) => $q->where('valor_total', '>=', $v))
            ->when($filtros['valor_max'] ?? null, fn (Builder $q, $v) => $q->where('valor_total', '<=', $v))
            ->when($filtros['chave'] ?? null, fn (Builder $q, $c) => $q->where('chave', $c))
            ->when($filtros['q'] ?? null, fn (Builder $q, $termo) => $q->where(function (Builder $sub) use ($termo) {
                $sub->where('emitente_nome', 'ilike', "%{$termo}%")
                    ->orWhere('numero', 'ilike', "%{$termo}%")
                    ->orWhere('chave', 'ilike', "%{$termo}%");
            }));
    }

    /**
     * Ordena a listagem. Sem `ordem`, vale a emissão mais recente primeiro.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function ordenar(Builder $q, array $filtros): Builder
    {
        return Ordenacao::aplicar($q, self::ORDENACOES, $filtros['ordem'] ?? 'emissao', $filtros['direcao'] ?? 'desc');
    }

    /**
     * Eager load da unidade da nota (nome/CNPJ), inclusive unidade desativada
     * por soft delete: a nota continua sendo dela.
     *
     * @return array<string, \Closure>
     */
    public static function comUnidade(): array
    {
        return [
            'unidade' => fn ($q) => $q->withTrashed()->select('id', 'tenant_id', 'nome', 'nome_resumido', 'cnpj'),
        ];
    }
}
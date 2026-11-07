<?php

namespace App\Domain\Nfse;

use App\Models\Nfse;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta de NFS-e a partir dos filtros da API. A listagem e a exportação
 * usam a mesma montagem. O escopo de tenant vem do global scope.
 */
class NfseQuery
{
    /**
     * @param  array<string, mixed>  $filtros
     */
    public function build(array $filtros): Builder
    {
        $porCompetencia = ($filtros['data'] ?? 'emissao') === 'competencia';
        $campo = $porCompetencia ? 'competencia' : 'data_emissao';

        return Nfse::query()
            ->when($filtros['de'] ?? null, fn (Builder $q, $de) => $q->where($campo, '>=', $porCompetencia ? $de : $de.' 00:00:00'))
            ->when($filtros['ate'] ?? null, fn (Builder $q, $ate) => $q->where($campo, '<=', $porCompetencia ? $ate : $ate.' 23:59:59'))
            ->when($filtros['unidade_id'] ?? null, fn (Builder $q, $u) => $q->where('unidade_id', $u))
            ->when($filtros['papel'] ?? null, fn (Builder $q, $p) => $q->where('papel', $p))
            ->when($filtros['situacao'] ?? null, fn (Builder $q, $s) => $q->where('situacao', $s))
            ->when($filtros['prestador_documento'] ?? null, fn (Builder $q, $c) => $q->where('prestador_documento', $c))
            ->when($filtros['valor_min'] ?? null, fn (Builder $q, $v) => $q->where('valor_servico', '>=', $v))
            ->when($filtros['valor_max'] ?? null, fn (Builder $q, $v) => $q->where('valor_servico', '<=', $v))
            ->when($filtros['chave'] ?? null, fn (Builder $q, $c) => $q->where('chave', $c))
            ->when($filtros['q'] ?? null, fn (Builder $q, $termo) => $q->where(function (Builder $sub) use ($termo) {
                $sub->where('prestador_nome', 'ilike', "%{$termo}%")
                    ->orWhere('tomador_nome', 'ilike', "%{$termo}%")
                    ->orWhere('numero', 'ilike', "%{$termo}%")
                    ->orWhere('prestador_documento', 'ilike', "%{$termo}%")
                    ->orWhere('chave', 'ilike', "%{$termo}%")
                    ->orWhere('descricao_servico', 'ilike', "%{$termo}%");
            }));
    }

    /**
     * Campo de ordenação, coerente com o filtro de data escolhido.
     *
     * @param  array<string, mixed>  $filtros
     */
    public static function campoData(array $filtros): string
    {
        return ($filtros['data'] ?? 'emissao') === 'competencia' ? 'competencia' : 'data_emissao';
    }

    /**
     * @return array<string, \Closure>
     */
    public static function comUnidade(): array
    {
        return [
            'unidade' => fn ($q) => $q->withTrashed()->select('id', 'tenant_id', 'nome', 'nome_resumido', 'cnpj'),
        ];
    }
}

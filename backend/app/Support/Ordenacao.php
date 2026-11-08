<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ordenação das listagens a partir de `?ordem=` e `?direcao=`.
 *
 * A coluna NUNCA vem do cliente: `ordem` é uma chave da lista branca e cada
 * chave aponta para uma coluna e um tipo. Assim o SQL bruto abaixo só recebe
 * nomes de coluna fixos no código e uma direção já reduzida a asc/desc.
 *
 * - texto: alfabética sem diferenciar maiúsculas nem acentos ("Água" junto de "agua").
 * - numero: número guardado como texto ("9" antes de "10").
 * - data/valor: ordem natural da coluna.
 *
 * Vazios vão sempre para o fim, nos dois sentidos. O `id` desempata para a
 * paginação ser estável (a mesma nota não aparece em duas páginas).
 */
final class Ordenacao
{
    public const TEXTO = 'texto';

    public const NUMERO = 'numero';

    public const NATURAL = 'natural';

    public const DIRECOES = ['asc', 'desc'];

    private const COM_ACENTO = 'áàâãäéèêëíìîïóòôõöúùûüçñ';

    private const SEM_ACENTO = 'aaaaaeeeeiiiiooooouuuucn';

    /**
     * @param  array<string, array{0: string, 1: string}>  $permitidas  chave => [coluna, tipo]
     */
    public static function aplicar(Builder $q, array $permitidas, string $chave, ?string $direcao): Builder
    {
        [$coluna, $tipo] = $permitidas[$chave];
        $dir = $direcao === 'asc' ? 'asc' : 'desc';

        $expressao = match ($tipo) {
            self::TEXTO => "translate(lower({$coluna}), '".self::COM_ACENTO."', '".self::SEM_ACENTO."')",
            self::NUMERO => "lpad({$coluna}, 20, '0')",
            default => $coluna,
        };

        return $q->orderByRaw("{$expressao} {$dir} nulls last")->orderBy('id', $dir);
    }
}

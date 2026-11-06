<?php

namespace App\Domain\Sefaz;

/**
 * Desfecho de uma recuperação pontual de NF-e (por chave ou por NSU).
 *
 * Serve para alimentar a notificação ao usuário e a auditoria. O rótulo reflete
 * o que EFETIVAMENTE entrou no banco — um `cStat 138` sem NF-e importada não é
 * `ENCONTRADO`.
 */
class ResultadoRecuperacao
{
    /** Ao menos uma NF-e foi importada. */
    public const ENCONTRADO = 'encontrado';

    /** A SEFAZ não tinha o documento (cStat 137), ou nada importável veio. */
    public const NAO_ENCONTRADO = 'nao_encontrado';

    /** Freio acionado (cStat 656 ou circuit breaker/rate limiter). */
    public const BLOQUEADO = 'bloqueado';

    /** Erro inesperado da SEFAZ (cStat diferente de 137/138/656). */
    public const ERRO = 'erro';

    public function __construct(
        public readonly string $status,
        public readonly int $docsCount = 0,
        public readonly ?string $motivo = null,
    ) {}

    public static function de(string $status, int $docsCount = 0, ?string $motivo = null): self
    {
        return new self($status, $docsCount, $motivo);
    }
}

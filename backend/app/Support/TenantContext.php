<?php

namespace App\Support;

/**
 * Mantém o tenant (entidade/órgão) ativo durante a requisição, job ou comando.
 *
 * O contexto é a fonte de verdade para o global scope do trait BelongsToTenant.
 * Quando não há tenant definido, nenhum filtro é aplicado — o que permite
 * operações administrativas globais (ex.: varredura de certificados vencidos).
 */
class TenantContext
{
    protected static ?int $tenantId = null;

    /**
     * Define o tenant ativo. Passar null limpa o contexto.
     */
    public static function set(?int $tenantId): void
    {
        static::$tenantId = $tenantId;
    }

    /**
     * Retorna o id do tenant ativo, ou null quando não há contexto.
     */
    public static function id(): ?int
    {
        return static::$tenantId;
    }

    /**
     * Limpa o tenant ativo (contexto administrativo/global).
     */
    public static function clear(): void
    {
        static::$tenantId = null;
    }

    /**
     * Executa o callback sob o tenant informado e restaura o contexto anterior.
     *
     * @template T
     * @param  callable():T  $callback
     * @return T
     */
    public static function runAs(int $tenantId, callable $callback): mixed
    {
        $anterior = static::$tenantId;

        static::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            static::$tenantId = $anterior;
        }
    }
}
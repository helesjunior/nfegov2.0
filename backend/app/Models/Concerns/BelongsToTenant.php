<?php

namespace App\Models\Concerns;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aplica isolamento multitenant a um modelo Eloquent.
 *
 * - Toda consulta recebe automaticamente o filtro `where tenant_id = <ativo>`.
 * - Ao criar um registro, o `tenant_id` é preenchido com o tenant ativo,
 *   evitando que o chamador precise (ou possa) esquecer de informá-lo.
 * - Sem contexto de tenant, nenhum filtro é aplicado (uso administrativo).
 */
trait BelongsToTenant
{
    /**
     * Registra o global scope e o preenchimento automático do tenant.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = TenantContext::id();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->getTable() . '.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            $tenantId = TenantContext::id();

            if ($tenantId !== null && empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            }
        });
    }

    /**
     * Consulta ignorando o filtro de tenant (uso exclusivamente administrativo).
     */
    public static function withoutTenantScope(): Builder
    {
        return static::withoutGlobalScope('tenant');
    }
}
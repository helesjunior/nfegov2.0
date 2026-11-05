<?php

namespace App\Policies\Concerns;

use App\Models\User;

/**
 * Regras comuns de autorização por tenant.
 *
 * Camadas:
 * - `super-admin`  : acesso global (administra a plataforma).
 * - `admin-tenant` : acesso pleno DENTRO do próprio tenant.
 * - demais papéis  : dependem das permissões específicas (checadas nas policies).
 *
 * O isolamento por tenant é combinado com o global scope (`BelongsToTenant`):
 * a policy nega explicitamente recursos de outro tenant. As rotas devolvem 404
 * nesses casos, para não revelar a existência do dado.
 */
trait PertenceAoTenant
{
    /**
     * O usuário tem acesso ao tenant do recurso?
     *
     * O vínculo é resolvido pela relação N:N (`tenant_user`), que é a fonte de
     * verdade — NÃO pela coluna legada `users.tenant_id` (que só guarda o
     * "tenant principal" de conveniência). Assim, um usuário vinculado a vários
     * tenants alcança recursos de qualquer um deles, e um `tenant_id` legado
     * divergente do vínculo não concede acesso indevido.
     */
    protected function mesmoTenant(User $user, $recurso): bool
    {
        if ($user->tenant_id === null) {
            // Usuários sem tenant (super-admin) podem alcançar qualquer dado;
            // a checagem de papel é feita à parte na policy concreta.
            return true;
        }

        if ($recurso->tenant_id === null) {
            return false;
        }

        return $user->tenants()->whereKey($recurso->tenant_id)->exists();
    }

    protected function ehSuperAdmin(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    protected function ehAdminTenant(User $user): bool
    {
        return $user->hasRole('admin-tenant');
    }

    /**
     * Acesso de leitura ao recurso: super-admin sempre; demais precisam
     * pertencer ao tenant E ter a permissão informada (se houver).
     */
    protected function podeVer(User $user, $recurso, ?string $permissao = null): bool
    {
        if ($this->ehSuperAdmin($user)) {
            return true;
        }

        if (! $this->mesmoTenant($user, $recurso)) {
            return false;
        }

        return $permissao === null || $user->can($permissao);
    }
}
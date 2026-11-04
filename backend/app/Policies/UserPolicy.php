<?php

namespace App\Policies;

use App\Models\User;

/**
 * Autorização do CRUD de Usuários.
 *
 * O escopo é N:N (via `tenant_user`), não a coluna legada `users.tenant_id`.
 * - super-admin: qualquer usuário.
 * - admin-tenant/gestor (`usuario.gerenciar`): só usuários com vínculo a algum
 *   tenant que ele também acessa.
 * - Ninguém altera campos sensíveis da própria conta (feito no controller).
 */
class UserPolicy
{
    public function viewAny(User $auth): bool
    {
        return $auth->hasRole('super-admin') || $auth->can('usuario.gerenciar');
    }

    public function create(User $auth): bool
    {
        return $auth->hasRole('super-admin') || $auth->can('usuario.gerenciar');
    }

    public function update(User $auth, User $alvo): bool
    {
        if (! ($auth->hasRole('super-admin') || $auth->can('usuario.gerenciar'))) {
            return false;
        }

        if ($auth->hasRole('super-admin')) {
            return true;
        }

        return $this->compartilhaTenant($auth, $alvo);
    }

    public function desativar(User $auth, User $alvo): bool
    {
        return $auth->id !== $alvo->id && $this->update($auth, $alvo);
    }

    private function compartilhaTenant(User $auth, User $alvo): bool
    {
        $meus = $auth->tenants()->pluck('tenants.id');

        return $alvo->tenants()->whereIn('tenants.id', $meus)->exists();
    }
}

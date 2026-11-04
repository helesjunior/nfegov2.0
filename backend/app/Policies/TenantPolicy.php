<?php

namespace App\Policies;

use App\Models\User;

/**
 * Autorização para Entidades (Tenant). Somente super-admin.
 */
class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function view(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function update(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function delete(User $user): bool
    {
        return $user->hasRole('super-admin');
    }
}
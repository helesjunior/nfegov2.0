<?php

namespace App\Policies;

use App\Models\SyncRun;
use App\Models\User;
use App\Policies\Concerns\PertenceAoTenant;

/**
 * Autorização para execuções de sincronização (auditoria técnica).
 *
 * Ver o histórico exige participação no tenant; disparar sync exige
 * `sync.executar`. Execuções são registros imutáveis.
 */
class SyncRunPolicy
{
    use PertenceAoTenant;

    public function viewAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('nfe.view');
    }

    public function view(User $user, SyncRun $run): bool
    {
        return $this->podeVer($user, $run, 'nfe.view');
    }

    public function create(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('sync.executar');
    }
}
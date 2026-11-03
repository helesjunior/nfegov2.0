<?php

namespace App\Policies;

use App\Models\Nfe;
use App\Models\User;
use App\Policies\Concerns\PertenceAoTenant;

/**
 * Autorização para NF-e.
 *
 * Toda leitura/exportação respeita o tenant e as permissões `nfe.view` /
 * `nfe.export`. NF-e são imutáveis: não há `update`/`delete`.
 */
class NfePolicy
{
    use PertenceAoTenant;

    public function viewAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('nfe.view');
    }

    public function view(User $user, Nfe $nfe): bool
    {
        return $this->podeVer($user, $nfe, 'nfe.view');
    }

    public function export(User $user, Nfe $nfe): bool
    {
        return $this->podeVer($user, $nfe, 'nfe.export');
    }

    /**
     * Exportação em massa (sem uma NF-e específica): exige `nfe.export`.
     */
    public function exportAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('nfe.export');
    }

    public function downloadXml(User $user, Nfe $nfe): bool
    {
        return $this->podeVer($user, $nfe, 'nfe.export');
    }

    public function manifestar(User $user, Nfe $nfe): bool
    {
        return $this->podeVer($user, $nfe, 'nfe.export');
    }
}
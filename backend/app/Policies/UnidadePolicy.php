<?php

namespace App\Policies;

use App\Models\Unidade;
use App\Models\User;
use App\Policies\Concerns\PertenceAoTenant;

/**
 * Autorização para Unidades gestoras.
 *
 * - `view`/`viewAny`: quem enxerga dados do tenant (ex.: `consulta`).
 * - `create`/`update`/`delete`: exigem `unidade.gerenciar` (admin-tenant).
 * - `viewCertificate`: metadados do certificado (sem senha/PFX).
 */
class UnidadePolicy
{
    use PertenceAoTenant;

    public function viewAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('nfe.view');
    }

    public function view(User $user, Unidade $unidade): bool
    {
        return $this->podeVer($user, $unidade, 'nfe.view');
    }

    public function create(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('unidade.gerenciar');
    }

    public function update(User $user, Unidade $unidade): bool
    {
        return $this->mesmoTenant($user, $unidade)
            && ($this->ehSuperAdmin($user) || $user->can('unidade.gerenciar'));
    }

    public function delete(User $user, Unidade $unidade): bool
    {
        return $this->mesmoTenant($user, $unidade)
            && ($this->ehSuperAdmin($user) || $user->can('unidade.gerenciar'));
    }

    /**
     * Ver os metadados do certificado da unidade (nunca senha/PFX).
     */
    public function viewCertificate(User $user, Unidade $unidade): bool
    {
        return $this->mesmoTenant($user, $unidade)
            && ($this->ehSuperAdmin($user) || $user->can('certificado.gerenciar'));
    }
}
<?php

namespace App\Policies;

use App\Models\Nfse;
use App\Models\User;
use App\Policies\Concerns\PertenceAoTenant;

/**
 * Autorização para NFS-e. Usa as mesmas permissões da NF-e (`nfe.view` para
 * consultar, `nfe.export` para XML, DANFSe, exportação e manifestação): quem
 * opera as notas de produtos opera também as de serviço. NFS-e são imutáveis.
 */
class NfsePolicy
{
    use PertenceAoTenant;

    public function viewAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('nfe.view');
    }

    public function view(User $user, Nfse $nfse): bool
    {
        return $this->podeVer($user, $nfse, 'nfe.view');
    }

    public function exportAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('nfe.export');
    }

    public function downloadXml(User $user, Nfse $nfse): bool
    {
        return $this->podeVer($user, $nfse, 'nfe.export');
    }

    public function export(User $user, Nfse $nfse): bool
    {
        return $this->podeVer($user, $nfse, 'nfe.export');
    }

    /**
     * Manifestação do tomador: ato fiscal, só para quem pode exportar a nota.
     */
    public function manifestar(User $user, Nfse $nfse): bool
    {
        return $this->podeVer($user, $nfse, 'nfe.export');
    }
}

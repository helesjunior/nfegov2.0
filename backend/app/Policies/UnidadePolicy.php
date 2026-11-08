<?php

namespace App\Policies;

use App\Domain\Certificate\CertificateService;
use App\Models\Unidade;
use App\Models\User;
use App\Policies\Concerns\PertenceAoTenant;

/**
 * Autorização para Unidades gestoras.
 *
 * - `viewAny`/`view`: quem consulta notas (`nfe.view`) lista as unidades, pois
 *   elas alimentam os filtros de NF-e/NFS-e. Isso NÃO dá acesso à tela.
 * - `acessarTela`: a tela de Unidades (menu) exige `unidade.view`.
 * - `create`/`update`/`delete`: exigem `unidade.gerenciar` (admin-tenant).
 * - `viewCertificate`: metadados do certificado (sem senha/PFX).
 * - `atualizarCertificado`: `certificado.gerenciar` envia a qualquer momento;
 *   `certificado.renovar` (operador) só quando o certificado atual venceu.
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

    public function acessarTela(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('unidade.view');
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
        return $this->update($user, $unidade);
    }

    /**
     * Ver os metadados do certificado da unidade (nunca senha/PFX).
     */
    public function viewCertificate(User $user, Unidade $unidade): bool
    {
        return $this->mesmoTenant($user, $unidade)
            && ($this->ehSuperAdmin($user)
                || $user->can('unidade.view')
                || $user->can('certificado.gerenciar'));
    }

    /**
     * Enviar/trocar o certificado da unidade.
     */
    public function atualizarCertificado(User $user, Unidade $unidade): bool
    {
        if (! $this->mesmoTenant($user, $unidade)) {
            return false;
        }

        if ($this->ehSuperAdmin($user) || $user->can('certificado.gerenciar')) {
            return true;
        }

        if (! $user->can('certificado.renovar')) {
            return false;
        }

        $atual = app(CertificateService::class)->activeFor($unidade);

        return $atual !== null && ($atual->vencido() || $atual->foiExpirado());
    }
}

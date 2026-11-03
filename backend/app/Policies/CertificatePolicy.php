<?php

namespace App\Policies;

use App\Models\Certificate;
use App\Models\User;
use App\Policies\Concerns\PertenceAoTenant;

/**
 * Autorização para certificados e-CNPJ.
 *
 * A senha e o PFX NUNCA são expostos por nenhuma ação — apenas metadados.
 * Gerenciar (criar/rotacionar/desativar) exige `certificado.gerenciar`.
 * A rotação ainda passa pelo step-up (`mfa.recent`) na camada de rota.
 */
class CertificatePolicy
{
    use PertenceAoTenant;

    public function viewAny(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('certificado.gerenciar');
    }

    public function view(User $user, Certificate $certificate): bool
    {
        return $this->mesmoTenant($user, $certificate)
            && ($this->ehSuperAdmin($user) || $user->can('certificado.gerenciar'));
    }

    public function create(User $user): bool
    {
        return $this->ehSuperAdmin($user) || $user->can('certificado.gerenciar');
    }

    public function rotate(User $user, Certificate $certificate): bool
    {
        return $this->mesmoTenant($user, $certificate)
            && ($this->ehSuperAdmin($user) || $user->can('certificado.gerenciar'));
    }

    public function delete(User $user, Certificate $certificate): bool
    {
        return $this->mesmoTenant($user, $certificate)
            && ($this->ehSuperAdmin($user) || $user->can('certificado.gerenciar'));
    }
}
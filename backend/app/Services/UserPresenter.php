<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;

/**
 * Representação segura do usuário para a SPA (sem senha/segredo).
 *
 * Fonte única usada por /login, /me e pelas rotas de perfil, para que a SPA
 * receba sempre o mesmo formato e não reimplemente regras (ex.: quem exige MFA).
 */
class UserPresenter
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly TenantAtivoResolver $resolver,
    ) {
    }

    public function paraUsuario(User $user, ?int $preferenciaTenant = null): array
    {
        $exigeMfa = $user->hasAnyRole(config('nfegov.mfa.papeis_obrigatorios', ['super-admin', 'admin-tenant']));

        $tenantAtivoId = $this->resolver->paraUsuario($user, $preferenciaTenant);
        $tenantAtivo = $tenantAtivoId ? Tenant::find($tenantAtivoId) : null;
        $disponiveis = $this->resolver->disponiveis($user)->map(fn ($t) => ['id' => $t->id, 'nome' => $t->nome]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tenant_id' => $user->tenant_id,
            'tenant_ativo' => $tenantAtivo ? ['id' => $tenantAtivo->id, 'nome' => $tenantAtivo->nome] : null,
            'tenants_disponiveis' => $disponiveis,
            'pode_todas' => $this->resolver->podeTodas($user),
            'papeis' => $user->getRoleNames(),
            'permissoes' => $user->getAllPermissions()->pluck('name'),
            'mfa_obrigatorio' => $exigeMfa,
            'mfa_pendente' => $exigeMfa && ! $this->twoFactor->confirmado($user),
            'mfa_confirmado' => $this->twoFactor->confirmado($user),
        ];
    }
}
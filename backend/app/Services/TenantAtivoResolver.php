<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Resolve o tenant ATIVO de um usuário (fonte única para middleware e /me).
 *
 * Regras (spec §2):
 * - super-admin: a preferência da sessão (pode ser null = "Todas"); se apontar
 *   para tenant inexistente/inativo, degrada para "Todas".
 * - demais: a preferência, SE houver vínculo com um tenant ATIVO; senão o
 *   primeiro vínculo ativo (menor tenant_id); senão null (o middleware decide 403).
 */
class TenantAtivoResolver
{
    public function podeTodas(User $user): bool
    {
        return $user->hasRole('super-admin');
    }

    public function paraUsuario(User $user, ?int $preferencia = null): ?int
    {
        if ($this->podeTodas($user)) {
            if ($preferencia === null) {
                return null;
            }

            return $this->ativo($preferencia) ? (int) $preferencia : null;
        }

        $vinculados = $this->vinculosAtivos($user);

        if ($preferencia !== null && $vinculados->contains((int) $preferencia)) {
            return (int) $preferencia;
        }

        return $vinculados->first();
    }

    public function usuarioSemVinculo(User $user): bool
    {
        return ! $this->podeTodas($user) && $this->vinculosAtivos($user)->isEmpty();
    }

    /** @return Collection<int,Tenant> */
    public function disponiveis(User $user): Collection
    {
        if ($this->podeTodas($user)) {
            return Tenant::where('ativo', true)->orderBy('nome')->get();
        }

        return Tenant::where('ativo', true)
            ->whereIn('id', $this->vinculosAtivos($user)->all())
            ->orderBy('nome')
            ->get();
    }

    private function ativo(int $tenantId): bool
    {
        return Tenant::whereKey($tenantId)->where('ativo', true)->exists();
    }

    /** @return Collection<int,int> ids de tenants ativos vinculados, ordenados */
    private function vinculosAtivos(User $user): Collection
    {
        return Tenant::where('ativo', true)
            ->whereIn('id', $user->tenants()->pluck('tenants.id')->all())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantAtivoResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Seleção do tenant ativo da sessão (seletor de entidade do SPA).
 */
class SessaoTenantController extends Controller
{
    public function __construct(private readonly TenantAtivoResolver $resolver)
    {
    }

    public function update(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'tenant_id' => ['present', 'nullable', 'integer'],
        ]);

        $user = $request->user();
        $tenantId = $dados['tenant_id'];

        if ($tenantId === null) {
            if (! $this->resolver->podeTodas($user)) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Selecione uma entidade.',
                ]);
            }
        } else {
            if (! $this->resolver->podeTodas($user)) {
                // Trocar para entidade sem vínculo é falta de autorização.
                abort_unless(
                    $this->resolver->disponiveis($user)->contains('id', (int) $tenantId),
                    403,
                );
            } elseif (! Tenant::whereKey($tenantId)->where('ativo', true)->exists()) {
                throw ValidationException::withMessages([
                    'tenant_id' => 'Entidade indisponível.',
                ]);
            }
        }

        $request->session()->put('tenant_id', $tenantId);

        $tenant = $tenantId === null ? null : Tenant::find($tenantId);

        return response()->json([
            'tenant_ativo' => $tenant ? ['id' => $tenant->id, 'nome' => $tenant->nome] : null,
        ]);
    }
}
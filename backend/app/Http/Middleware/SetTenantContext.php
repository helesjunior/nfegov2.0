<?php

namespace App\Http\Middleware;

use App\Services\TenantAtivoResolver;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant ativo a partir da SESSÃO, validado contra os vínculos.
 *
 * - Resolve o tenant efetivo via TenantAtivoResolver (fonte única).
 * - Não-super-admin sem vínculo ativo recebe 403 (nunca contexto nulo, que
 *   desativaria o filtro e vazaria dados de todos os tenants).
 * - Usuário inativo não navega (sessão cai na próxima requisição).
 * - Limpa o contexto ao final (evita vazamento entre requisições/workers).
 */
class SetTenantContext
{
    public function __construct(private readonly TenantAtivoResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            if ((bool) ($user->ativo ?? true) === false) {
                return response()->json(['message' => 'Usuário inativo.'], 403);
            }

            if ($this->resolver->usuarioSemVinculo($user)) {
                return response()->json([
                    'message' => 'Usuário sem entidade vinculada.',
                ], 403);
            }

            $preferencia = $request->session()->get('tenant_id');
            $tenantId = $this->resolver->paraUsuario(
                $user,
                $preferencia === null ? null : (int) $preferencia,
            );

            TenantContext::set($tenantId);
        }

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
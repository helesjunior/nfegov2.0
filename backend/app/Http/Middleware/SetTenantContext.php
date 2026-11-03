<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Define o tenant ativo a partir do usuário autenticado.
 *
 * Sem isto, o global scope (`BelongsToTenant`) não teria contexto e não
 * filtraria nada — deixando vazar dados entre entidades. Roda depois da
 * autenticação e limpa o contexto ao final da requisição.
 *
 * Usuários sem tenant (super-admin) permanecem sem contexto: enxergam todos
 * os dados, com a autorização fina feita pelas policies.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        TenantContext::set($user?->tenant_id);

        try {
            return $next($request);
        } finally {
            // Evita vazamento de contexto entre requisições (ex.: workers).
            TenantContext::clear();
        }
    }
}
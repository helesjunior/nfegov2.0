<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Registra a trilha de auditoria das ações sensíveis.
 *
 * Centraliza quem/o quê/quando/de onde. NUNCA recebe segredos: o chamador
 * passa apenas metadados (jamais senha, PFX ou conteúdo de XML).
 *
 * O `tenant_id` é resolvido, nesta ordem: tenant explícito, contexto ativo e
 * tenant do usuário; `user_id` também pode vir do usuário autenticado, o que
 * cobre jobs/CLI (sem request).
 */
class AuditLogger
{
    /**
     * Grava um evento de auditoria.
     *
     * @param  string  $acao        ex.: certificado.rotacionado, nfe.xml.baixado
     * @param  array   $contexto    metadados NÃO sensíveis
     * @param  string|null $entidade  tipo do recurso (ex.: unidade, nfe)
     * @param  string|int|null $entidadeId  identificador do recurso
     * @param  int|null $tenantId  tenant explícito (vence o contexto e o usuário)
     */
    public function log(
        ?User $user,
        string $acao,
        array $contexto = [],
        ?string $entidade = null,
        string|int|null $entidadeId = null,
        ?Request $request = null,
        ?int $tenantId = null,
    ): AuditLog {
        $request ??= request();

        // Preferência: tenant explícito > contexto ativo > tenant do usuário.
        $tenantId ??= \App\Support\TenantContext::id() ?? $user?->tenant_id;

        return AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $user?->id ?? Auth::id(),
            'acao' => $acao,
            'entidade' => $entidade,
            'entidade_id' => $entidadeId === null ? null : (string) $entidadeId,
            'contexto' => $contexto ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }
}
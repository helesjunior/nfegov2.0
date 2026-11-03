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
 * O `tenant_id` é resolvido do usuário (ou do contexto ativo); `user_id`
 * também pode vir do usuário autenticado, o que cobre jobs/CLI (sem request).
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
     */
    public function log(
        ?User $user,
        string $acao,
        array $contexto = [],
        ?string $entidade = null,
        string|int|null $entidadeId = null,
        ?Request $request = null,
    ): AuditLog {
        $request ??= request();

        $tenantId = $user?->tenant_id ?? \App\Support\TenantContext::id();

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
<?php

namespace App\Models\Concerns;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Atalho para registrar auditoria dentro de controllers.
 *
 * Injeta o `AuditLogger` do container e resolve o usuário autenticado, para
 * que a instrumentação fique em uma linha: `$this->audit('acao', [...])`.
 *
 * IMPORTANTE: o contexto carrega apenas metadados; nunca senha/PFX/XML.
 */
trait Auditable
{
    protected function audit(
        string $acao,
        array $contexto = [],
        ?string $entidade = null,
        string|int|null $entidadeId = null,
        ?User $user = null,
        ?Request $request = null,
        ?int $tenantId = null,
    ): void {
        app(AuditLogger::class)->log(
            $user ?? $request?->user() ?? request()->user(),
            $acao,
            $contexto,
            $entidade,
            $entidadeId,
            $request ?? request(),
            $tenantId,
        );
    }
}
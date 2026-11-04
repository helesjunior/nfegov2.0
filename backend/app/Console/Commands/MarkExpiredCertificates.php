<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Marca explicitamente os certificados e-CNPJ vencidos (`expirado_em`).
 *
 * Objetivo: deixar o BANCO explícito sobre o vencimento, sem apagar a
 * informação operacional. Um certificado vencido NÃO é desativado — `ativo`
 * continua significando "certificado corrente da unidade" (rotação em
 * `CertificateService::store`). Assim, o certificado aparece no dashboard,
 * nos alertas por e-mail e na tela da unidade, agora com o carimbo de que
 * venceu.
 *
 * Idempotente: só toca quem ainda não tem `expirado_em`, preservando o
 * momento real em que o carimbo foi gravado.
 *
 * Roda SEM contexto de tenant, para cobrir todos os tenants de uma vez
 * (mesmo padrão do `certificate-alerts:check`).
 */
class MarkExpiredCertificates extends Command
{
    protected $signature = 'certificate-expiry:mark';

    protected $description = 'Marca como expirados os certificados e-CNPJ vencidos (sem desativá-los)';

    public function handle(): int
    {
        // Garante varredura global (todos os tenants).
        TenantContext::clear();

        // Atualização em massa: apenas os ativos, vencidos e ainda não
        // marcados. `whereNull('expirado_em')` mantém a idempotência.
        $total = Certificate::withoutGlobalScopes()
            ->where('ativo', true)
            ->whereNull('expirado_em')
            ->whereNotNull('valido_ate')
            ->where('valido_ate', '<', now())
            ->update(['expirado_em' => now()]);

        $this->info("Certificados marcados como expirados: {$total}");

        return self::SUCCESS;
    }
}
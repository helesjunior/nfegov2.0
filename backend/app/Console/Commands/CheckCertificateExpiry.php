<?php

namespace App\Console\Commands;

use App\Mail\CertificateExpiringMail;
use App\Models\Certificate;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Verifica o vencimento dos certificados e-CNPJ ativos e envia alertas
 * por e-mail aos administradores do tenant, nos marcos configurados
 * (`nfegov.certificado.alert_days`, padrão: 60/30/15/7/1 dias).
 *
 * Idempotente: cada marco gera no máximo um alerta por certificado
 * (registro em `certificate_alerts`).
 *
 * Roda SEM contexto de tenant, para cobrir todos os tenants de uma vez.
 */
class CheckCertificateExpiry extends Command
{
    protected $signature = 'certificate-alerts:check';

    protected $description = 'Envia alertas de vencimento dos certificados e-CNPJ (por e-mail)';

    public function handle(): int
    {
        // Garante varredura global (todos os tenants).
        TenantContext::clear();

        $marcos = collect(config('nfegov.certificado.alert_days', [60, 30, 15, 7, 1]))
            ->map(fn ($d) => (int) $d)
            ->all();

        $enviados = 0;

        Certificate::withoutGlobalScopes()
            ->where('ativo', true)
            ->with(['unidade', 'tenant'])
            ->chunkById(200, function ($certificados) use ($marcos, &$enviados) {
                foreach ($certificados as $cert) {
                    if ($cert->valido_ate === null) {
                        continue;
                    }

                    $dias = $cert->diasRestantes();

                    // Certificado já vencido ou fora dos marcos: nada a fazer.
                    if ($dias < 0 || ! in_array($dias, $marcos, true)) {
                        continue;
                    }

                    $enviados += $this->alertar($cert, $dias);
                }
            });

        $this->info("Alertas de vencimento enviados: {$enviados}");

        return self::SUCCESS;
    }

    /**
     * Grava o alerta (idempotente) e envia o e-mail aos admins do tenant.
     */
    private function alertar(Certificate $cert, int $dias): int
    {
        // `firstOrCreate` garante idempotência por marco/certificado.
        $alerta = $cert->alerts()->firstOrCreate(
            ['dias_restantes' => $dias],
            ['tenant_id' => $cert->tenant_id, 'enviado_em' => now()],
        );

        // Já existia (já alertamos este marco): não reenvia.
        if (! $alerta->wasRecentlyCreated) {
            return 0;
        }

        $admins = $cert->tenant
            ?->users()
            ->whereHas('roles', fn ($q) => $q->where('name', 'admin-tenant'))
            ->get() ?? collect();

        $count = 0;
        foreach ($admins as $admin) {
            Mail::to($admin->email)->send(new CertificateExpiringMail($cert, $cert->unidade, $dias));
            $count++;
        }

        return $count;
    }
}
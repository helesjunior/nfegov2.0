<?php

namespace App\Jobs;

use App\Domain\Sefaz\DistDfeService;
use App\Domain\Sefaz\SefazGuard;
use App\Models\SyncRun;
use App\Models\Unidade;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sincroniza o distDFe de UMA unidade.
 *
 * `ShouldBeUnique` por unidade evita execuções concorrentes da mesma unidade
 * (o que geraria consumo duplicado no mesmo NSU). Roda no `TenantContext` da
 * unidade e respeita o circuit breaker global.
 */
class SyncNfeJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $unidadeId)
    {
        $this->onQueue(config('nfegov.sefaz.queue', 'sefaz'));
    }

    /**
     * Chave de unicidade: uma execução por unidade.
     */
    public function uniqueId(): string
    {
        return 'sync-unidade-'.$this->unidadeId;
    }

    public function handle(DistDfeService $distDfe, SefazGuard $guard): void
    {
        $unidade = Unidade::withoutGlobalScopes()->find($this->unidadeId);

        if (! $unidade) {
            return;
        }

        TenantContext::runAs($unidade->tenant_id, function () use ($unidade, $distDfe, $guard) {
            // Circuit breaker aberto: nem tenta.
            if ($guard->isOpen()) {
                SyncRun::create([
                    'tenant_id' => $unidade->tenant_id,
                    'unidade_id' => $unidade->id,
                    'iniciado_em' => now(),
                    'finalizado_em' => now(),
                    'status' => SyncRun::STATUS_BLOQUEADO,
                    'erro' => 'Circuito da SEFAZ aberto.',
                ]);

                return;
            }

            $run = SyncRun::create([
                'tenant_id' => $unidade->tenant_id,
                'unidade_id' => $unidade->id,
                'iniciado_em' => now(),
                'status' => SyncRun::STATUS_EM_ANDAMENTO,
            ]);

            try {
                $distDfe->sync($unidade, $run);
            } catch (Throwable $e) {
                $run->forceFill([
                    'finalizado_em' => now(),
                    'status' => SyncRun::STATUS_ERRO,
                    'erro' => $e->getMessage(),
                ])->save();

                throw $e;
            }
        });
    }
}
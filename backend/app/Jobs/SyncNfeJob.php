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
            // Certificado vencido/ausente: NÃO toca a SEFAZ. Consultar com
            // certificado inválido consome NSU e leva à rejeição (caminho do
            // cStat 656). Revalidamos aqui porque a fila pode envelhecer entre
            // o agendamento e a execução.
            if (! $this->certificadoVigente($unidade)) {
                SyncRun::create([
                    'tenant_id' => $unidade->tenant_id,
                    'unidade_id' => $unidade->id,
                    'iniciado_em' => now(),
                    'finalizado_em' => now(),
                    'status' => SyncRun::STATUS_BLOQUEADO,
                    'erro' => 'Certificado e-CNPJ vencido ou ausente.',
                ]);

                return;
            }

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

    /**
     * A unidade possui certificado e-CNPJ ativo e VIGENTE?
     *
     * Considera `valido_ate` no futuro. Um certificado sem validade conhecida
     * (`valido_ate` nulo) é tratado como não-vigente: é mais seguro falhar
     * fechado do que consultar a SEFAZ com dados incompletos.
     */
    private function certificadoVigente(Unidade $unidade): bool
    {
        // Buscamos o certificado ATIVO mais recente (não o de maior id global):
        // um certificado inativo mais novo não pode mascarar o vigente ativo.
        $certificado = $unidade->certificates()
            ->where('ativo', true)
            ->latest('id')
            ->first();

        return $certificado !== null
            && $certificado->valido_ate !== null
            && $certificado->valido_ate->isFuture();
    }
}

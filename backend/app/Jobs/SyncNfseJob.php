<?php

namespace App\Jobs;

use App\Domain\Nfse\AdnGuard;
use App\Domain\Nfse\DistNfseService;
use App\Models\SyncRun;
use App\Models\Unidade;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Consome a fila de NFS-e (ADN) de UMA unidade.
 *
 * Mesmas garantias do `SyncNfeJob`: uma execução por unidade
 * (`ShouldBeUnique`), roda no `TenantContext` da unidade, não chama o ADN com
 * certificado vencido e respeita o freio global do ADN.
 */
class SyncNfseJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 3600;

    public int $timeout = 900;

    public function __construct(public readonly int $unidadeId)
    {
        $this->onQueue(config('nfegov.adn.queue', 'adn'));
    }

    public function uniqueId(): string
    {
        return 'sync-nfse-unidade-'.$this->unidadeId;
    }

    public function handle(DistNfseService $service, AdnGuard $guard): void
    {
        $unidade = Unidade::withoutGlobalScopes()->find($this->unidadeId);

        if (! $unidade) {
            return;
        }

        TenantContext::runAs($unidade->tenant_id, function () use ($unidade, $service, $guard) {
            if (! $this->certificadoVigente($unidade)) {
                $this->registrarBloqueio($unidade, 'Certificado e-CNPJ vencido ou ausente.');

                return;
            }

            if ($guard->isOpen()) {
                $this->registrarBloqueio($unidade, 'Circuito do ADN (NFS-e) aberto.');

                return;
            }

            $run = SyncRun::create([
                'tenant_id' => $unidade->tenant_id,
                'unidade_id' => $unidade->id,
                'tipo' => SyncRun::TIPO_NFSE,
                'iniciado_em' => now(),
                'status' => SyncRun::STATUS_EM_ANDAMENTO,
            ]);

            try {
                $service->sync($unidade, $run);
            } catch (Throwable $e) {
                $run->forceFill([
                    'finalizado_em' => now(),
                    'status' => SyncRun::STATUS_ERRO,
                    'erro' => mb_substr($e->getMessage(), 0, 2000),
                ])->save();

                throw $e;
            }
        });
    }

    private function registrarBloqueio(Unidade $unidade, string $motivo): void
    {
        SyncRun::create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'tipo' => SyncRun::TIPO_NFSE,
            'iniciado_em' => now(),
            'finalizado_em' => now(),
            'status' => SyncRun::STATUS_BLOQUEADO,
            'erro' => $motivo,
        ]);
    }

    private function certificadoVigente(Unidade $unidade): bool
    {
        $certificado = $unidade->certificates()
            ->where('ativo', true)
            ->latest('id')
            ->first();

        return $certificado !== null
            && $certificado->valido_ate !== null
            && $certificado->valido_ate->isFuture();
    }
}

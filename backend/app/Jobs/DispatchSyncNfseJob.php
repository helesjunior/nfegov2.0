<?php

namespace App\Jobs;

use App\Domain\Nfse\AdnGuard;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Distribui a sincronização de NFS-e (ADN) entre as unidades ativas, com o
 * mesmo escalonamento da NF-e (cada unidade num slot da janela).
 *
 * Não faz nada com a NFS-e desligada (`NFEGOV_ADN_HABILITADO=false`) ou com
 * o freio do ADN aberto.
 */
class DispatchSyncNfseJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue(config('nfegov.adn.queue', 'adn'));
    }

    public function handle(AdnGuard $guard): void
    {
        if (! config('nfegov.adn.habilitado', true) || $guard->isOpen()) {
            return;
        }

        $unidades = Unidade::withoutGlobalScopes()
            ->where('ativo', true)
            ->whereIn('tenant_id', Tenant::where('ativo', true)->pluck('id'))
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('certificates')
                    ->whereColumn('certificates.unidade_id', 'unidades.id')
                    ->where('certificates.ativo', true)
                    ->where('certificates.valido_ate', '>', now());
            })
            ->get();

        foreach ($unidades as $unidade) {
            SyncNfseJob::dispatch($unidade->id)
                ->delay(now()->addSeconds($guard->slotPara($unidade)));
        }
    }
}

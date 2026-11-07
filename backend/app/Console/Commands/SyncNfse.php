<?php

namespace App\Console\Commands;

use App\Jobs\DispatchSyncNfseJob;
use App\Jobs\SyncNfseJob;
use App\Models\Unidade;
use Illuminate\Console\Command;

/**
 * Dispara a recepção de NFS-e pelo ADN.
 *
 * Sem argumentos, distribui todas as unidades ativas (`DispatchSyncNfseJob`).
 * Com `--unidade=ID`, enfileira só aquela unidade. Nunca chama o ADN no
 * processo do comando: só enfileira, para respeitar o freio e a fila `adn`.
 */
class SyncNfse extends Command
{
    protected $signature = 'nfegov:sync-nfse {--unidade= : ID da unidade específica}';

    protected $description = 'Recebe as NFS-e das unidades pelo Ambiente de Dados Nacional (ADN)';

    public function handle(): int
    {
        if (! config('nfegov.adn.habilitado', true)) {
            $this->warn('Recepção de NFS-e desligada (NFEGOV_ADN_HABILITADO=false).');

            return self::SUCCESS;
        }

        if ($id = $this->option('unidade')) {
            $unidade = Unidade::withoutGlobalScopes()->find($id);

            if (! $unidade) {
                $this->error("Unidade {$id} não encontrada.");

                return self::FAILURE;
            }

            SyncNfseJob::dispatch($unidade->id);
            $this->info("Recepção de NFS-e da unidade {$unidade->id} enfileirada.");

            return self::SUCCESS;
        }

        DispatchSyncNfseJob::dispatch();
        $this->info('Recepção de NFS-e enfileirada para as unidades ativas.');

        return self::SUCCESS;
    }
}

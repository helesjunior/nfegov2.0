<?php

namespace App\Console\Commands;

use App\Jobs\DispatchSyncNfeJob;
use App\Jobs\SyncNfeJob;
use App\Models\Unidade;
use Illuminate\Console\Command;

/**
 * Dispara a sincronização do distDFe.
 *
 * Sem argumentos, distribui TODAS as unidades ativas via `DispatchSyncNfeJob`
 * (escalonadas na janela de 3h). Com `--unidade=ID`, sincroniza apenas uma
 * unidade — útil para operação manual e diagnóstico.
 *
 * NUNCA fala com a SEFAZ no processo do comando: apenas enfileira, para que o
 * freio global e a concorrência da fila `sefaz` sejam respeitados.
 */
class SyncNfe extends Command
{
    protected $signature = 'nfegov:sync-nfe {--unidade= : ID da unidade específica}';

    protected $description = 'Sincroniza o distDFe (recepção de NF-e) das unidades';

    public function handle(): int
    {
        if ($id = $this->option('unidade')) {
            $unidade = Unidade::withoutGlobalScopes()->find($id);

            if (! $unidade) {
                $this->error("Unidade {$id} não encontrada.");

                return self::FAILURE;
            }

            SyncNfeJob::dispatch($unidade->id);
            $this->info("Sincronização da unidade {$unidade->id} enfileirada.");

            return self::SUCCESS;
        }

        DispatchSyncNfeJob::dispatch();
        $this->info('Distribuição da sincronização de NF-e enfileirada para as unidades ativas.');

        return self::SUCCESS;
    }
}
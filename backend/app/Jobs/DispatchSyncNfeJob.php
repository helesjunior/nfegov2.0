<?php

namespace App\Jobs;

use App\Domain\Sefaz\SefazGuard;
use App\Models\Unidade;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Distribui o trabalho de sincronização entre as unidades ativas.
 *
 * Em vez de disparar todas as unidades ao mesmo tempo (o que geraria um pico
 * de consultas e risco de bloqueio do IP compartilhado), escalona cada uma
 * dentro da janela configurada (`slot_window_minutes`) usando o `SefazGuard`.
 *
 * Com o circuit breaker aberto, não enfileira nada: o sync fica suspenso
 * globalmente até o circuito fechar.
 */
class DispatchSyncNfeJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue(config('nfegov.sefaz.queue', 'sefaz'));
    }

    public function handle(SefazGuard $guard): void
    {
        // Circuito aberto: nenhuma unidade deve consultar a SEFAZ agora.
        if ($guard->isOpen()) {
            return;
        }

        $unidades = Unidade::withoutGlobalScopes()->where('ativo', true)->get();

        foreach ($unidades as $unidade) {
            $slot = $guard->slotPara($unidade);

            // Slot 0 = imediato; os demais entram com atraso crescente dentro
            // da janela, espalhando as chamadas ao longo do tempo.
            SyncNfeJob::dispatch($unidade->id)
                ->onQueue(config('nfegov.sefaz.queue', 'sefaz'))
                ->delay(now()->addSeconds($slot));
        }
    }
}
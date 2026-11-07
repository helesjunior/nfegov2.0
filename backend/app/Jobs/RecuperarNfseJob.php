<?php

namespace App\Jobs;

use App\Domain\Nfse\AdnClient;
use App\Domain\Nfse\AdnGuard;
use App\Domain\Nfse\NfseDocumentoProcessor;
use App\Models\Nfse;
use App\Models\Unidade;
use App\Models\User;
use App\Notifications\NfseBuscaConcluida;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Busca manual de uma NFS-e por chave de acesso (`GET /NFSe/{chave}/Eventos`).
 *
 * Traz a nota e os eventos dela sem mexer no cursor NSU da unidade. Roda na
 * fila `adn`, respeita o freio do ADN e avisa o usuário ao terminar. Não há
 * retentativa automática: a consulta é barata e o usuário pode repetir.
 */
class RecuperarNfseJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** Tempo (s) em que um pedido idêntico em andamento é recusado. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $unidadeId,
        public readonly int $userId,
        public readonly string $chave,
    ) {
        $this->onQueue(config('nfegov.adn.queue', 'adn'));
    }

    public function chaveIdempotencia(): string
    {
        return 'nfse:recuperar:'.$this->unidadeId.':'.$this->chave;
    }

    public function handle(AdnClient $client, AdnGuard $guard, NfseDocumentoProcessor $processor): void
    {
        try {
            $unidade = Unidade::withoutGlobalScopes()->find($this->unidadeId);

            if (! $unidade) {
                return;
            }

            TenantContext::runAs($unidade->tenant_id, function () use ($unidade, $client, $guard, $processor) {
                $this->buscar($unidade, $client, $guard, $processor);
            });
        } finally {
            Cache::forget($this->chaveIdempotencia());
        }
    }

    private function buscar(Unidade $unidade, AdnClient $client, AdnGuard $guard, NfseDocumentoProcessor $processor): void
    {
        try {
            $guard->beforeCall($unidade);
            $resposta = $client->porChave($unidade, $this->chave);
        } catch (Throwable $e) {
            // Circuito aberto/teto por minuto ou falha de conexão: não é "nota inexistente".
            $this->avisar($guard->isOpen() ? NfseBuscaConcluida::BLOQUEADA : NfseBuscaConcluida::ERRO, $e->getMessage());

            return;
        }

        if ($resposta->deveAbrirFreio()) {
            $guard->tripCircuitBreaker($unidade);
            $this->avisar(NfseBuscaConcluida::BLOQUEADA);

            return;
        }

        if ($resposta->filaVazia()) {
            // Ex.: E2241 (a chave não referencia o contribuinte) ou E2240 (sem documento).
            $this->avisar(NfseBuscaConcluida::NAO_ENCONTRADA, $resposta->mensagemErro());

            return;
        }

        if (! $resposta->sucesso()) {
            $this->avisar(NfseBuscaConcluida::ERRO, $resposta->mensagemErro());

            return;
        }

        $avisos = [];
        foreach ($resposta->documentos as $documento) {
            $aviso = $processor->processar($unidade, $documento);
            if ($aviso !== null) {
                $avisos[] = $aviso;
            }
        }

        if ($avisos !== []) {
            $this->avisar(NfseBuscaConcluida::ERRO, implode(' | ', array_slice($avisos, 0, 3)));

            return;
        }

        $nota = Nfse::where('unidade_id', $unidade->id)->where('chave', $this->chave)->exists();

        $this->avisar($nota ? NfseBuscaConcluida::ENCONTRADA : NfseBuscaConcluida::NAO_ENCONTRADA);
    }

    private function avisar(string $status, ?string $detalhe = null): void
    {
        User::withoutGlobalScopes()->find($this->userId)
            ?->notify(new NfseBuscaConcluida($this->chave, $status, $detalhe));
    }
}

<?php

namespace App\Domain\Nfse;

use App\Domain\Sefaz\DocZipDecoder;
use App\Models\NfseNsu;
use App\Models\SyncRun;
use App\Models\Unidade;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Consome a fila de NFS-e de uma unidade no ADN (`GET /DFe/{NSU}`).
 *
 * Mesmo desenho do `DistDfeService` da NF-e:
 * - cursor NSU por unidade, que só avança DEPOIS de cada documento gravado;
 * - teto de chamadas e de documentos por execução (a carga do histórico,
 *   que o ADN entrega desde o primeiro documento do CNPJ, se divide em
 *   várias execuções);
 * - pausa entre chamadas e freio global próprio (`AdnGuard`).
 *
 * Diferenças do ADN: o lote tem até 50 documentos; o fim da fila é HTTP 404
 * (`NENHUM_DOCUMENTO_LOCALIZADO`), não há `maxNSU` nem cStat 656.
 *
 * Um documento que não abre (XML corrompido) não trava a fila: vira aviso no
 * `SyncRun` e o cursor segue.
 */
class DistNfseService
{
    public const TAMANHO_LOTE = 50;

    public function __construct(
        private readonly AdnClient $client,
        private readonly NfseDocumentoProcessor $processor,
        private readonly AdnGuard $guard,
    ) {}

    public function sync(Unidade $unidade, SyncRun $run): void
    {
        $maxIteracoes = (int) config('nfegov.adn.max_iteracoes', 20);
        $maxDocs = (int) config('nfegov.adn.max_docs', 1000);
        $pausa = (int) config('nfegov.adn.sleep_segundos', 1);

        $cursor = NfseNsu::withoutGlobalScopes()->firstOrCreate(
            ['unidade_id' => $unidade->id],
            ['tenant_id' => $unidade->tenant_id, 'ultimo_nsu' => 0],
        );

        $ultimoNsu = (int) $cursor->ultimo_nsu;

        $run->forceFill([
            'iniciado_em' => $run->iniciado_em ?? now(),
            'status' => SyncRun::STATUS_EM_ANDAMENTO,
            'nsu_inicial' => $ultimoNsu,
        ])->save();

        $docs = 0;
        $avisos = [];

        try {
            for ($i = 0; $i < $maxIteracoes; $i++) {
                $this->guard->beforeCall($unidade);

                $resposta = $this->client->distribuicao($unidade, $ultimoNsu);

                if ($resposta->deveAbrirFreio()) {
                    $this->guard->tripCircuitBreaker($unidade);
                    $this->finalizar($run, SyncRun::STATUS_BLOQUEADO, $ultimoNsu, $docs, $resposta->mensagemErro());

                    return;
                }

                if ($resposta->filaVazia()) {
                    $this->marcarHistoricoConcluido($cursor);
                    break;
                }

                if (! $resposta->sucesso()) {
                    $this->finalizar($run, SyncRun::STATUS_ERRO, $ultimoNsu, $docs, $resposta->mensagemErro());

                    return;
                }

                foreach ($resposta->documentos as $documento) {
                    $aviso = $this->processor->processar($unidade, $documento);
                    if ($aviso !== null) {
                        $avisos[] = $aviso;
                    }

                    $docs++;
                    $ultimoNsu = max($ultimoNsu, $documento['nsu']);
                    $cursor->forceFill(['ultimo_nsu' => $ultimoNsu])->save();

                    if ($docs >= $maxDocs) {
                        break 2;
                    }
                }

                $cursor->forceFill(['dh_resp' => now()])->save();

                // Lote incompleto: chegamos ao fim da fila, sem gastar mais
                // uma chamada só para receber o 404.
                if (count($resposta->documentos) < self::TAMANHO_LOTE) {
                    $this->marcarHistoricoConcluido($cursor);
                    break;
                }

                if ($pausa > 0) {
                    sleep($pausa);
                }
            }

            $this->finalizar($run, SyncRun::STATUS_SUCESSO, $ultimoNsu, $docs, $this->resumoAvisos($avisos));
        } catch (Throwable $e) {
            $this->finalizar($run, SyncRun::STATUS_ERRO, $ultimoNsu, $docs, $e->getMessage());

            throw $e;
        } finally {
            $cursor->forceFill(['ultimo_nsu' => $ultimoNsu, 'dh_resp' => now()])->save();
        }
    }

    private function marcarHistoricoConcluido(NfseNsu $cursor): void
    {
        if ($cursor->historico_concluido_em === null) {
            $cursor->forceFill(['historico_concluido_em' => now()])->save();
        }
    }

    /**
     * @param  array<int, string>  $avisos
     */
    private function resumoAvisos(array $avisos): ?string
    {
        if ($avisos === []) {
            return null;
        }

        return count($avisos).' documento(s) ignorado(s). '.implode(' | ', array_slice($avisos, 0, 5));
    }

    private function finalizar(SyncRun $run, string $status, int $nsuFinal, int $docs, ?string $erro): void
    {
        $run->forceFill([
            'finalizado_em' => now(),
            'nsu_final' => $nsuFinal,
            'docs_count' => $docs,
            'status' => $status,
            'erro' => $erro !== null ? mb_substr($erro, 0, 2000) : null,
        ])->save();
    }
}

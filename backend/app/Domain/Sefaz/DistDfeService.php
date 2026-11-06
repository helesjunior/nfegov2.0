<?php

namespace App\Domain\Sefaz;

use App\Domain\Nfe\DocumentoProcessor;
use App\Models\Nsu;
use App\Models\SyncRun;
use App\Models\Unidade;
use Throwable;

/**
 * Orquestra a recepção do distDFe para uma unidade.
 *
 * FREIO ANTI-BLOQUEIO (réplica dos limites do sistema legado):
 * - No máximo `max_iteracoes` consultas por execução (150).
 * - No máximo `max_docs` documentos processados por execução (100).
 * - `sleep(2)` entre consultas, para não martelar a SEFAZ.
 * - Para IMEDIATAMENTE ao receber cStat 656 (consumo indevido), evitando a
 *   escalada para bloqueio do IP compartilhado.
 *
 * Documentos `resNFe` (resumo) disparam a ciência da operação e a reconsulta
 * do download; `procNFe` são importados direto.
 */
class DistDfeService
{
    public function __construct(
        private readonly SefazClient $client,
        private readonly DocumentoProcessor $processor,
        private readonly SefazGuard $guard,
    ) {}

    /**
     * Executa o ciclo de sincronização de uma unidade, atualizando `$run`.
     */
    public function sync(Unidade $unidade, SyncRun $run): void
    {
        $config = config('nfegov.sefaz');
        $maxIteracoes = (int) ($config['max_iteracoes'] ?? 150);
        $maxDocs = (int) ($config['max_docs'] ?? 100);
        $pausa = (int) ($config['sleep_segundos'] ?? 2);

        $nsu = Nsu::withoutGlobalScopes()->firstOrCreate(
            ['unidade_id' => $unidade->id],
            ['tenant_id' => $unidade->tenant_id, 'ultimo_nsu' => 0, 'max_nsu' => 0],
        );

        $run->forceFill([
            'iniciado_em' => now(),
            'status' => SyncRun::STATUS_EM_ANDAMENTO,
            'nsu_inicial' => $nsu->ultimo_nsu,
        ])->save();

        $ultimoNsu = (int) $nsu->ultimo_nsu;
        $docs = 0;

        try {
            for ($i = 0; $i < $maxIteracoes; $i++) {
                // Freio global: respeita circuit breaker + rate limiter antes
                // de tocar a SEFAZ. Lança se o circuito estiver aberto ou se o
                // teto global por minuto foi atingido.
                $this->guard->beforeCall($unidade);

                $retorno = $this->client->distDFe($unidade, $ultimoNsu);
                $envelope = (new EnvelopeDistDfe)->parse($retorno);

                // cStat 656 (consumo indevido): abre o circuit breaker GLOBAL
                // e para imediatamente — protege o IP de todas as unidades.
                if ($envelope['cStat'] === '656') {
                    $this->guard->tripCircuitBreaker($unidade);

                    $run->forceFill(['status' => SyncRun::STATUS_BLOQUEADO])->save();

                    return;
                }

                if (! in_array($envelope['cStat'], ['137', '138'], true)) {
                    $run->forceFill(['status' => SyncRun::STATUS_ERRO, 'erro' => $envelope['xMotivo']])->save();

                    return;
                }

                $nsuPrevio = $ultimoNsu;
                $envelope['ultNSU'] > $ultimoNsu && $ultimoNsu = (int) $envelope['ultNSU'];

                foreach ($envelope['docs'] as $doc) {
                    $this->processor->processar($unidade, $nsu, $doc);
                    $docs++;

                    if ($docs >= $maxDocs) {
                        break 2;
                    }
                }

                $nsu->forceFill([
                    'ultimo_nsu' => $ultimoNsu,
                    'max_nsu' => (int) $envelope['maxNSU'],
                    'dh_resp' => now(),
                ])->save();

                // A SEFAZ avança o NSU em lotes VAZIOS (cStat 137, "Nenhum documento
                // localizado") até chegar aos documentos do contribuinte. Por
                // isso NÃO se encerra no primeiro lote vazio — só quando
                // alcançamos o maxNSU informado (ou o teto de iterações).
                if ($ultimoNsu >= (int) $envelope['maxNSU']) {
                    break;
                }

                // Segurança: se a SEFAZ não avançou o NSU (lote vazio e
                // ultNSU estagnado), não há o que consumir — evita girar em
                // vão até o teto de iterações.
                if ($ultimoNsu <= $nsuPrevio && empty($envelope['docs'])) {
                    break;
                }

                // Freio: pausa entre chamadas à SEFAZ.
                if ($pausa > 0) {
                    sleep($pausa);
                }
            }

            $run->forceFill([
                'finalizado_em' => now(),
                'nsu_final' => $ultimoNsu,
                'docs_count' => $docs,
                'status' => SyncRun::STATUS_SUCESSO,
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'finalizado_em' => now(),
                'docs_count' => $docs,
                'status' => SyncRun::STATUS_ERRO,
                'erro' => $e->getMessage(),
            ])->save();

            throw $e;
        } finally {
            $nsu->forceFill(['ultimo_nsu' => $ultimoNsu, 'dh_resp' => now()])->save();
        }
    }

    /**
     * Interpreta o `retDistDFeInt`.
     *
     * @return array{cStat:string, xMotivo:string, ultNSU:int, maxNSU:int, docs:array<int, array{nsu:int, schema:string, conteudo:string}>}
     */
    private function parseRetorno(string $xml): array
    {
        return (new EnvelopeDistDfe)->parse($xml);
    }
}

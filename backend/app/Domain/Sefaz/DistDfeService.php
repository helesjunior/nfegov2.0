<?php

namespace App\Domain\Sefaz;

use App\Domain\Nfe\NfeImporter;
use App\Domain\Nfe\XmlStore;
use App\Models\Nfe;
use App\Models\Nsu;
use App\Models\SyncRun;
use App\Models\Unidade;
use Illuminate\Support\Facades\DB;
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
        private readonly DocZipDecoder $decoder,
        private readonly NfeImporter $importer,
        private readonly ManifestacaoService $manifestacao,
        private readonly DownloadService $download,
        private readonly SefazGuard $guard,
    ) {
    }

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
                $envelope = $this->parseRetorno($retorno);

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

                $envelope['ultNSU'] > $ultimoNsu && $ultimoNsu = (int) $envelope['ultNSU'];

                foreach ($envelope['docs'] as $doc) {
                    $this->processarDoc($unidade, $nsu, $doc, $ultimoNsu);
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

                // Nada novo: encerra o laço.
                if ($ultimoNsu >= (int) $envelope['maxNSU'] || empty($envelope['docs'])) {
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
     * Processa um documento do lote, conforme o schema.
     *
     * @param  array{nsu:int, schema:string, conteudo:string}  $doc
     */
    private function processarDoc(Unidade $unidade, Nsu $nsu, array $doc, int $nsuAtual): void
    {
        $xml = $this->decoder->decode($doc['conteudo']);

        if ($doc['schema'] === 'resNFe') {
            $this->importer->importResumo($unidade, $nsu, $xml);

            // Ciência da operação + reconsulta do XML completo.
            $chave = $this->chaveDoResumo($xml);
            if ($chave) {
                $this->manifestacao->ciencia($unidade, $chave);
                $completo = $this->download->porChave($unidade, $chave);

                if ($completo) {
                    $this->importer->import($unidade, $nsu, $completo);
                }
            }

            return;
        }

        // procNFe / nfeProc / resEvento: apenas documentos de NF-e são importados.
        if (in_array($doc['schema'], ['procNFe', 'nfeProc'], true)) {
            $this->importer->import($unidade, $nsu, $xml);
        }
    }

    private function chaveDoResumo(string $xml): ?string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $chave = preg_replace('/\D/', '', (string) $xp->query('//nfe:chNFe')->item(0)?->nodeValue);

        return strlen($chave) === 44 ? $chave : null;
    }

    /**
     * Interpreta o `retDistDFeInt`.
     *
     * @return array{cStat:string, xMotivo:string, ultNSU:int, maxNSU:int, docs:array<int, array{nsu:int, schema:string, conteudo:string}>}
     */
    private function parseRetorno(string $xml): array
    {
        $dom = new \DOMDocument();
        @$dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $docs = [];
        foreach ($xp->query('//nfe:loteDistDFeInt/nfe:docZip') as $node) {
            $docs[] = [
                'nsu' => (int) $node->attributes->getNamedItem('NSU')?->nodeValue,
                'schema' => (string) $node->attributes->getNamedItem('schema')?->nodeValue,
                'conteudo' => trim($node->nodeValue),
            ];
        }

        return [
            'cStat' => (string) $xp->query('//nfe:cStat')->item(0)?->nodeValue,
            'xMotivo' => (string) $xp->query('//nfe:xMotivo')->item(0)?->nodeValue,
            'ultNSU' => (int) $xp->query('//nfe:ultNSU')->item(0)?->nodeValue,
            'maxNSU' => (int) $xp->query('//nfe:maxNSU')->item(0)?->nodeValue,
            'docs' => $docs,
        ];
    }
}
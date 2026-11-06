<?php

namespace App\Domain\Sefaz;

use App\Domain\Nfe\DocumentoProcessor;
use App\Models\Nfe;
use App\Models\Nsu;
use App\Models\Unidade;
use Throwable;

/**
 * Recuperação pontual de NF-e que já passaram pela fila do DistDFe.
 *
 * Duas formas, espelhando as consultas da SEFAZ:
 * - `porNsu()`: consulta um NSU específico (`consNSU`).
 * - `porChave()`: consulta pela chave de acesso (`consChNFe`), com um ciclo de
 *   resgate em três tentativas (ver `porChave`).
 *
 * IMPORTANTE: esta operação é LATERAL ao cursor incremental — NUNCA toca
 * `nsu.ultimo_nsu`. Recuperar um NSU antigo não pode fazer a sincronização
 * automática rebobinar e re-consumir a fila (risco de consumo indevido).
 *
 * Passa `Nsu = null` ao processador pelos mesmos motivos: não resolvemos nem
 * criamos a linha de cursor da unidade; só importamos a nota.
 */
class RecuperacaoService
{
    /**
     * Teto de iterações da varredura de resgate por chave (protege contra 656).
     */
    private const MAX_ITERACOES_VARREDURA = 50;

    public function __construct(
        private readonly SefazClient $client,
        private readonly DocumentoProcessor $processor,
        private readonly SefazGuard $guard,
        private readonly ManifestacaoService $manifestacao,
        private readonly DocZipDecoder $decoder,
    ) {}

    public function porNsu(Unidade $unidade, int $nsu): ResultadoRecuperacao
    {
        return $this->recuperar(
            $unidade,
            fn () => $this->client->distDFePorNsu($unidade, $nsu),
        );
    }

    /**
     * Busca por chave de acesso, em três tentativas, porque a SEFAZ NÃO entrega
     * uma nota que está na fila como resumo (`resNFe`) via `consChNFe` (devolve
     * `cStat 137`). O único caminho que a entrega é a varredura incremental:
     *
     *   1. `consChNFe` direto (nota já disponível como completo).
     *   2. Envia a Ciência da Operação (210210) e reconsulta `consChNFe`
     *      (a Ciência costuma liberar o completo para o destinatário).
     *   3. Varredura incremental (`ultNSU`) a partir do cursor local,
     *      procurando a chave — SEM alterar `nsu.ultimo_nsu`.
     *
     * A Ciência é irreversível, mas já é enviada automaticamente no fluxo
     * normal; aqui só antecipamos esse passo para destravar o resgate.
     */
    public function porChave(Unidade $unidade, string $chave): ResultadoRecuperacao
    {
        $chave = preg_replace('/\D/', '', $chave);

        try {
            $this->guard->beforeCall($unidade);
        } catch (Throwable $e) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, $e->getMessage());
        }

        // Tentativa 1: consulta direta por chave.
        $envelope = $this->consultar(fn () => $this->client->distDFePorChave($unidade, $chave));

        if ($envelope === null) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ERRO, 0, 'Falha ao consultar a SEFAZ.');
        }

        $bloqueio = $this->bloqueioOuErro($unidade, $envelope);
        if ($bloqueio !== null) {
            return $bloqueio;
        }

        $resultado = $this->processarEnvelope($unidade, $envelope, $chave);
        if ($resultado->status === ResultadoRecuperacao::ENCONTRADO) {
            return $resultado;
        }

        // Tentativa 2: Ciência da Operação + reconsulta por chave.
        $this->manifestacao->ciencia($unidade, $chave);

        $envelope = $this->consultar(fn () => $this->client->distDFePorChave($unidade, $chave));

        if ($envelope !== null) {
            $bloqueio = $this->bloqueioOuErro($unidade, $envelope);
            if ($bloqueio !== null) {
                return $bloqueio;
            }

            $resultado = $this->processarEnvelope($unidade, $envelope, $chave);
            if ($resultado->status === ResultadoRecuperacao::ENCONTRADO) {
                return $resultado;
            }
        }

        // Tentativa 3: varredura incremental procurando a chave (sem tocar o cursor).
        $achou = $this->varrerPorChave($unidade, $chave);

        return $achou
            ? ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, 1)
            : ResultadoRecuperacao::de(
                ResultadoRecuperacao::NAO_ENCONTRADO,
                0,
                'A SEFAZ não tem este documento na fila do destinatário.',
            );
    }

    /**
     * Consulta a SEFAZ (via `$consulta`) e interpreta o envelope, importando
     * as NF-e encontradas. Nunca lança por bloqueio: devolve um resultado.
     *
     * @param  callable():string  $consulta
     */
    private function recuperar(Unidade $unidade, callable $consulta): ResultadoRecuperacao
    {
        try {
            $this->guard->beforeCall($unidade);
        } catch (Throwable $e) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, $e->getMessage());
        }

        $envelope = $this->consultar($consulta);

        if ($envelope === null) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ERRO, 0, 'Falha ao consultar a SEFAZ.');
        }

        $bloqueio = $this->bloqueioOuErro($unidade, $envelope);
        if ($bloqueio !== null) {
            return $bloqueio;
        }

        return $this->processarEnvelope($unidade, $envelope, null);
    }

    /**
     * Consulta crua tolerante a falha: devolve o envelope ou null.
     *
     * @param  callable():string  $consulta
     */
    private function consultar(callable $consulta): ?array
    {
        try {
            return (new EnvelopeDistDfe)->parse($consulta());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * cStat 656 → trip + BLOQUEADO; cStat fora de {137,138} → ERRO. Caso
     * contrário, devolve null (o envelope segue para processamento).
     */
    private function bloqueioOuErro(Unidade $unidade, array $envelope): ?ResultadoRecuperacao
    {
        if ($envelope['cStat'] === '656') {
            $this->guard->tripCircuitBreaker($unidade);

            return ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, $envelope['xMotivo']);
        }

        if (! in_array($envelope['cStat'], ['137', '138'], true)) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ERRO, 0, $envelope['xMotivo']);
        }

        return null;
    }

    /**
     * Processa os documentos de um envelope. Quando `$chaveFiltro` é informada,
     * importa apenas o documento cuja chave coincide (a varredura incremental
     * pode trazer vários documentos).
     */
    private function processarEnvelope(Unidade $unidade, array $envelope, ?string $chaveFiltro): ResultadoRecuperacao
    {
        if ($envelope['cStat'] === '137') {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, $envelope['xMotivo']);
        }

        $antes = $this->contarNfes($unidade);

        foreach ($envelope['docs'] as $doc) {
            if ($chaveFiltro !== null && ! $this->docTemChave($doc, $chaveFiltro)) {
                continue;
            }

            $this->processor->processar($unidade, null, $doc);
        }

        $importadas = $this->contarNfes($unidade) - $antes;

        if ($importadas > 0) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, $importadas);
        }

        // Havia documento(s), mas nenhum virou NF-e (ex.: só evento).
        return ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, $envelope['xMotivo']);
    }

    /**
     * Varredura incremental a partir do cursor LOCAL da unidade, procurando um
     * documento com a chave. NUNCA grava o cursor: usa o valor em memória e
     * descarta ao fim. Respeita `MAX_ITERACOES_VARREDURA` para não virar um
     * gerador de cStat 656.
     */
    private function varrerPorChave(Unidade $unidade, string $chave): bool
    {
        $nsuLocal = Nsu::withoutGlobalScopes()
            ->where('unidade_id', $unidade->id)
            ->value('ultimo_nsu');

        // Sem cursor conhecido: começamos do zero (varredura completa).
        $ultimoNsu = (int) ($nsuLocal ?? 0);

        for ($i = 0; $i < self::MAX_ITERACOES_VARREDURA; $i++) {
            try {
                $this->guard->beforeCall($unidade);
            } catch (Throwable) {
                return false;
            }

            $envelope = $this->consultar(fn () => $this->client->distDFe($unidade, $ultimoNsu));

            if ($envelope === null || $envelope['cStat'] === '656') {
                if ($envelope !== null) {
                    $this->guard->tripCircuitBreaker($unidade);
                }

                return false;
            }

            foreach ($envelope['docs'] as $doc) {
                if (! $this->docTemChave($doc, $chave)) {
                    continue;
                }

                $antes = $this->contarNfes($unidade);
                $this->processor->processar($unidade, null, $doc);

                if ($this->contarNfes($unidade) > $antes) {
                    return true;
                }
            }

            $proximo = (int) $envelope['ultNSU'];
            $max = (int) $envelope['maxNSU'];

            // Fila esgotada ou a SEFAZ não avançou: nada mais a varrer.
            if ($proximo >= $max || $proximo <= $ultimoNsu) {
                return false;
            }

            $ultimoNsu = $proximo;

            // Freio entre chamadas (mesmo padrão do DistDfeService).
            $pausa = (int) (config('nfegov.sefaz.sleep_segundos') ?? 2);
            if ($pausa > 0) {
                sleep($pausa);
            }
        }

        return false;
    }

    /**
     * O documento decodificado é da chave informada? Aceita `resNFe` (chNFe)
     * e documentos completos (`infNFe/@Id`).
     */
    private function docTemChave(array $doc, string $chave): bool
    {
        try {
            $xml = $this->decoder->decode($doc['conteudo']);
        } catch (Throwable) {
            return false;
        }

        $dom = new \DOMDocument;
        @$dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $chNFe = preg_replace('/\D/', '', (string) $xp->query('//nfe:chNFe')->item(0)?->nodeValue);
        if (strlen($chNFe) === 44) {
            return $chNFe === $chave;
        }

        $id = (string) $xp->query('//nfe:infNFe')->item(0)?->attributes?->getNamedItem('Id')?->nodeValue;
        $id = preg_replace('/\D/', '', $id);

        return strlen($id) === 44 && $id === $chave;
    }

    private function contarNfes(Unidade $unidade): int
    {
        return Nfe::withoutGlobalScopes()
            ->where('unidade_id', $unidade->id)
            ->count();
    }
}

<?php

namespace App\Domain\Sefaz;

use App\Domain\Nfe\DocumentoProcessor;
use App\Models\Unidade;
use Throwable;

/**
 * Recuperação pontual de NF-e que já passaram pela fila do DistDFe.
 *
 * Duas formas, espelhando as consultas da SEFAZ:
 * - `porNsu()`: consulta um NSU específico (`consNSU`).
 * - `porChave()`: consulta pela chave de acesso (`consChNFe`).
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
    public function __construct(
        private readonly SefazClient $client,
        private readonly DocumentoProcessor $processor,
        private readonly SefazGuard $guard,
    ) {}

    public function porNsu(Unidade $unidade, int $nsu): ResultadoRecuperacao
    {
        return $this->recuperar(
            $unidade,
            fn () => $this->client->distDFePorNsu($unidade, $nsu),
        );
    }

    public function porChave(Unidade $unidade, string $chave): ResultadoRecuperacao
    {
        $chave = preg_replace('/\D/', '', $chave);

        return $this->recuperar(
            $unidade,
            fn () => $this->client->distDFePorChave($unidade, $chave),
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

        try {
            $envelope = (new EnvelopeDistDfe)->parse($consulta());
        } catch (Throwable $e) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ERRO, 0, $e->getMessage());
        }

        if ($envelope['cStat'] === '656') {
            $this->guard->tripCircuitBreaker($unidade);

            return ResultadoRecuperacao::de(ResultadoRecuperacao::BLOQUEADO, 0, $envelope['xMotivo']);
        }

        if (! in_array($envelope['cStat'], ['137', '138'], true)) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ERRO, 0, $envelope['xMotivo']);
        }

        if ($envelope['cStat'] === '137') {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, $envelope['xMotivo']);
        }

        // cStat 138: há documentos. Importamos (só NF-e; eventos são ignorados
        // pelo DocumentoProcessor). Contamos NF-e importadas.
        $antes = $this->contarNfes($unidade);

        foreach ($envelope['docs'] as $doc) {
            $this->processor->processar($unidade, null, $doc);
        }

        $importadas = $this->contarNfes($unidade) - $antes;

        if ($importadas > 0) {
            return ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, $importadas);
        }

        // Havia documento(s), mas nenhum virou NF-e (ex.: só evento, ou só
        // resumo cujo completo ainda não está disponível).
        return ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO, 0, $envelope['xMotivo']);
    }

    private function contarNfes(Unidade $unidade): int
    {
        return \App\Models\Nfe::withoutGlobalScopes()
            ->where('unidade_id', $unidade->id)
            ->count();
    }
}
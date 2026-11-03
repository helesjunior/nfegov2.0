<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Freio GLOBAL anti-bloqueio da SEFAZ (protege o IP compartilhado).
 *
 * Em instalação central multitenant, todas as unidades saem pelo mesmo IP.
 * Como o limite da SEFAZ é por IP, o freio precisa ser global — não por
 * tenant/unidade — para que um órgão não derrube o acesso dos demais.
 *
 * Camadas:
 * 1. Rate limiter: teto de consultas por minuto (`rate_per_minute`).
 * 2. Circuit breaker: ao receber cStat 656 (consumo indevido), pausa global
 *    por `circuit_breaker_minutes`.
 * 3. Escalonamento: distribui as unidades ao longo da janela de sync
 *    (`slot_window_minutes`), evitando picos simultâneos.
 */
class SefazGuard
{
    private const CHAVE_CIRCUITO = 'sefaz:circuito:aberto';

    private const CHAVE_RATE = 'sefaz:rate:';

    /**
     * Antes de qualquer chamada: respeita o circuit breaker e o rate limiter.
     *
     * @throws RuntimeException quando o circuito está aberto.
     */
    public function beforeCall(Unidade $unidade): void
    {
        if ($this->isOpen()) {
            throw new RuntimeException(
                'Circuito da SEFAZ aberto (consumo indevido). Novas chamadas suspensas temporariamente.'
            );
        }

        if (! $this->permitirChamada()) {
            throw new RuntimeException(
                'Limite global de consultas à SEFAZ por minuto atingido. Aguarde.'
            );
        }
    }

    /**
     * Consome uma "ficha" do rate limiter. Devolve false quando estourou.
     */
    public function permitirChamada(): bool
    {
        $teto = (int) config('nfegov.sefaz.rate_per_minute', 30);

        $chave = self::CHAVE_RATE . now()->format('YmdHi');

        // Incrementa e expira em ~2 minutos (limpa janelas antigas).
        $atual = Cache::increment($chave);

        if ($atual === 1) {
            Cache::put($chave, 1, now()->addMinutes(2));
        }

        return $atual <= $teto;
    }

    /**
     * Abre o circuit breaker global (chamado ao receber cStat 656).
     */
    public function tripCircuitBreaker(Unidade $unidade): void
    {
        $minutos = (int) config('nfegov.sefaz.circuit_breaker_minutes', 60);

        Cache::put(self::CHAVE_CIRCUITO, [
            'aberto_em' => now()->toIso8601String(),
            'unidade_id' => $unidade->id,
        ], now()->addMinutes($minutos));
    }

    public function isOpen(): bool
    {
        return Cache::has(self::CHAVE_CIRCUITO);
    }

    /**
     * Fecha o circuit breaker manualmente (operação administrativa).
     */
    public function reset(): void
    {
        Cache::forget(self::CHAVE_CIRCUITO);
    }

    /**
     * Calcula o atraso (em segundos) para a próxima execução da unidade,
     * distribuindo as unidades ao longo da janela (`slot_window_minutes`).
     *
     * O slot é determinístico por unidade (espalhamento estável) e sempre
     * dentro de [0, janela].
     */
    public function slotPara(Unidade $unidade): int
    {
        $janelaSegundos = ((int) config('nfegov.sefaz.slot_window_minutes', 180)) * 60;

        // Distribuição estável por unidade: hash do id define a posição
        // relativa [0,1). Determinístico (mesmo atraso a cada execução) e
        // bem espalhado mesmo com muitas unidades.
        $frac = hexdec(substr(hash('sha256', 'slot:'.$unidade->id), 0, 8)) / 0xFFFFFFFF;

        return (int) floor($frac * $janelaSegundos);
    }
}
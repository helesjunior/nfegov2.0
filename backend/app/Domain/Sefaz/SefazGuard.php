<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;
use Illuminate\Support\Carbon;
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
    /**
     * Prefixo das chaves de cache. Cada serviço externo tem o seu freio
     * (o `AdnGuard` da NFS-e usa outro), para um não parar o outro.
     */
    protected function prefixoCache(): string
    {
        return 'sefaz:';
    }

    /**
     * Lê a configuração do freio deste serviço (`nfegov.sefaz.*`).
     */
    protected function config(string $chave, int $padrao): int
    {
        return (int) config('nfegov.sefaz.'.$chave, $padrao);
    }

    protected function nomeServico(): string
    {
        return 'à SEFAZ';
    }

    protected function mensagemCircuitoAberto(): string
    {
        return 'Circuito da SEFAZ aberto (consumo indevido). Novas chamadas suspensas temporariamente.';
    }

    /**
     * Antes de qualquer chamada: respeita o circuit breaker e o rate limiter.
     *
     * @throws RuntimeException quando o circuito está aberto.
     */
    public function beforeCall(Unidade $unidade): void
    {
        if ($this->isOpen()) {
            throw new RuntimeException(
                $this->mensagemCircuitoAberto()
            );
        }

        if (! $this->permitirChamada()) {
            throw new RuntimeException(
                'Limite global de consultas '.$this->nomeServico().' por minuto atingido. Aguarde.'
            );
        }
    }

    /**
     * Consome uma "ficha" do rate limiter. Devolve false quando estourou.
     */
    public function permitirChamada(): bool
    {
        $teto = (int) $this->config('rate_per_minute', 30);

        $chave = $this->prefixoCache().'rate:'.now()->format('YmdHi');

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
        $minutos = (int) $this->config('circuit_breaker_minutes', 60);

        Cache::put($this->prefixoCache().'circuito:aberto', [
            'aberto_em' => now()->toIso8601String(),
            'unidade_id' => $unidade->id,
        ], now()->addMinutes($minutos));
    }

    public function isOpen(): bool
    {
        return Cache::has($this->prefixoCache().'circuito:aberto');
    }

    /**
     * Minutos restantes até o freio fechar. `0` quando fechado.
     *
     * Usado para adiar a próxima tentativa da recuperação manual: se o freio
     * está aberto, não adianta reagendar antes de ele fechar.
     */
    public function minutosRestantesDoFreio(): int
    {
        $dados = Cache::get($this->prefixoCache().'circuito:aberto');

        if (! is_array($dados) || ! isset($dados['aberto_em'])) {
            return 0;
        }

        $minutos = (int) $this->config('circuit_breaker_minutes', 60);
        $fechaEm = Carbon::parse($dados['aberto_em'])->addMinutes($minutos);

        return max(0, (int) ceil(now()->diffInSeconds($fechaEm, false) / 60));
    }

    /**
     * Fecha o circuit breaker manualmente (operação administrativa).
     */
    public function reset(): void
    {
        Cache::forget($this->prefixoCache().'circuito:aberto');
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
        $janelaSegundos = ((int) $this->config('slot_window_minutes', 180)) * 60;

        // Distribuição estável por unidade: hash do id define a posição
        // relativa [0,1). Determinístico (mesmo atraso a cada execução) e
        // bem espalhado mesmo com muitas unidades.
        $frac = hexdec(substr(hash('sha256', 'slot:'.$unidade->id), 0, 8)) / 0xFFFFFFFF;

        return (int) floor($frac * $janelaSegundos);
    }
}

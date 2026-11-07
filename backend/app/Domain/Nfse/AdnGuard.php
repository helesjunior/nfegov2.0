<?php

namespace App\Domain\Nfse;

use App\Domain\Sefaz\SefazGuard;

/**
 * Freio GLOBAL das chamadas ao Ambiente de Dados Nacional (NFS-e).
 *
 * Mesmo mecanismo do freio da SEFAZ (teto por minuto, circuit breaker e
 * escalonamento das unidades), com chaves de cache e configuração próprias
 * (`nfegov.adn.*`): um problema no ADN não para a NF-e, e vice-versa.
 *
 * O ADN não tem o cStat 656 da NF-e. O circuito abre quando o serviço pede
 * para esperar (HTTP 429/503) ou fica fora do ar.
 */
class AdnGuard extends SefazGuard
{
    protected function prefixoCache(): string
    {
        return 'adn:';
    }

    protected function config(string $chave, int $padrao): int
    {
        return (int) config('nfegov.adn.'.$chave, config('nfegov.sefaz.'.$chave, $padrao));
    }

    /**
     * A NFS-e tem agendamento próprio (3 h); usa só a janela do ADN.
     */
    protected function janelaMinutos(): int
    {
        return max(1, $this->config('slot_window_minutes', 180));
    }

    protected function nomeServico(): string
    {
        return 'ao ADN (NFS-e)';
    }

    protected function mensagemCircuitoAberto(): string
    {
        return 'Circuito do ADN (NFS-e) aberto. Novas chamadas suspensas temporariamente.';
    }
}

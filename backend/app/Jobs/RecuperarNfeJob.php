<?php

namespace App\Jobs;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Domain\Sefaz\SefazGuard;
use App\Models\Unidade;
use App\Models\User;
use App\Notifications\RecuperacaoConcluida;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Recupera MANUALMENTE uma NF-e que já passou pela fila do DistDFe.
 *
 * - Por chave de acesso (`consChNFe`) ou por NSU específico (`consNSU`).
 * - `ShouldBeUniqueUntilProcessing` por unidade + parâmetro: cliques repetidos
 *   não geram consultas simultâneas (protege contra consumo indevido/656). O
 *   lock é liberado no INÍCIO do processamento — essencial para o job poder
 *   reagendar a si mesmo (`self::dispatch`) quando a busca falha de forma
 *   retentável; com `ShouldBeUnique` (lock liberado só ao fim) o reagendamento
 *   seria silenciosamente descartado.
 * - Revalida certificado vigente e circuit breaker (igual ao `SyncNfeJob`).
 * - Roda no `TenantContext` da unidade e NOTIFICA o solicitante ao final.
 */
class RecuperarNfeJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 86400;

    /**
     * Tentativa atual (1 = original). Propriedade declarada fora da promoção,
     * com default de classe, para que payloads enfileirados antes desta versão
     * (que não trazem `tentativa`) desserializem com o valor 1 em vez de
     * estourar Typed property must not be accessed before initialization.
     */
    public int $tentativa = 1;

    /**
     * Marcado quando o job reagenda a si mesmo. O `finally` do `handle()` usa
     * isso para NÃO liberar a idempotência do controller durante a cadeia de
     * retentativas (ver `handle()`).
     */
    private bool $reagendado = false;

    public function __construct(
        public readonly int $unidadeId,
        public readonly int $userId,
        public readonly ?string $chave = null,
        public readonly ?int $nsu = null,
        int $tentativa = 1,
    ) {
        $this->tentativa = $tentativa;
        $this->onQueue(config('nfegov.sefaz.queue', 'sefaz'));
    }

    /**
     * Unicidade por unidade + parâmetro: uma busca idêntica por vez.
     */
    public function uniqueId(): string
    {
        return 'recuperar-unidade-'.$this->parametroChave();
    }

    /**
     * Chave de idempotência (cache) usada pela API para responder 409 a um
     * pedido idêntico em andamento. Liberada ao fim da execução.
     */
    public function chaveIdempotencia(): string
    {
        return 'nfe.recuperacao.'.$this->parametroChave();
    }

    private function parametroChave(): string
    {
        return $this->chave !== null
            ? 'chave-'.sha1(preg_replace('/\D/', '', $this->chave))
            : 'nsu-'.$this->nsu;
    }

    /**
     * Desfechos que valem uma nova tentativa automática.
     */
    public function deveReagendar(ResultadoRecuperacao $resultado): bool
    {
        return in_array($resultado->status, [
            ResultadoRecuperacao::NAO_ENCONTRADO,
            ResultadoRecuperacao::ERRO,
            ResultadoRecuperacao::BLOQUEADO,
        ], true);
    }

    /**
     * Segundos até a próxima tentativa. Para `bloqueado`, espera o freio;
     * nos demais, usa o intervalo progressivo da config.
     */
    public function delayDoReagendamento(ResultadoRecuperacao $resultado, SefazGuard $guard): int
    {
        $backoff = array_values((array) config('nfegov.recuperacao.backoff_minutes', [30, 120, 360]));
        $indice = max(0, $this->tentativa - 1);
        $minutos = $backoff[$indice] ?? (end($backoff) ?: 30);

        if ($resultado->status === ResultadoRecuperacao::BLOQUEADO) {
            $minutos = max($minutos, $guard->minutosRestantesDoFreio());
        }

        return max(60, (int) $minutos * 60);
    }

    /**
     * Há tentativas automáticas restantes?
     *
     * `backoff_minutes` tem um intervalo por tentativa automática. Com
     * `[30,120,360]`: a original (t=1) pode reagendar para t=2, t=2 → t=3 e
     * t=3 → t=4 (usando os delays 30min, 2h e 6h). Em t=4 não há mais
     * intervalo e o desfecho é final (total de 4 execuções, 3 automáticas).
     */
    private function temTentativasRestantes(): bool
    {
        return $this->tentativa <= count((array) config('nfegov.recuperacao.backoff_minutes', [30, 120, 360]));
    }

    /**
     * Despacha uma nova execução deste job para depois do delay informado.
     * `bloqueado` mantém a MESMA tentativa (freio não consome tentativa);
     * os demais avançam o contador.
     */
    private function reagendar(\DateTimeInterface $quando, bool $consomeTentativa = true): void
    {
        $proxima = $consomeTentativa ? $this->tentativa + 1 : $this->tentativa;

        $this->reagendado = true;

        self::dispatch($this->unidadeId, $this->userId, $this->chave, $this->nsu, $proxima)
            ->delay($quando);
    }

    public function handle(RecuperacaoService $recuperacao, SefazGuard $guard): void
    {
        $this->reagendado = false;

        try {
            $this->executar($recuperacao, $guard);
        } finally {
            // A idempotência do controller só é liberada no DESFECHO FINAL.
            // Enquanto há retentativa agendada, a chave permanece presa — senão
            // o usuário poderia disparar uma cadeia paralela durante a janela
            // de espera, duplicando o consumo na SEFAZ.
            if (! $this->reagendado) {
                Cache::forget($this->chaveIdempotencia());
            }
        }
    }

    private function executar(RecuperacaoService $recuperacao, SefazGuard $guard): void
    {
        $unidade = Unidade::withoutGlobalScopes()->find($this->unidadeId);

        if (! $unidade) {
            return;
        }

        TenantContext::runAs($unidade->tenant_id, function () use ($unidade, $recuperacao, $guard) {
            // SEM global scope: o solicitante pode ser super-admin (tenant_id
            // nulo) ou multi-tenant, e o `runAs` aqui é o da UNIDADE — usar o
            // escopo suprimiria a notificação. Mesma convenção do ExportNfeJob.
            $user = User::withoutGlobalScopes()->find($this->userId);

            $parametro = $this->chave !== null
                ? 'chave '.$this->chave
                : 'NSU '.$this->nsu;

            // Certificado vencido/ausente: não toca a SEFAZ (mesma regra do sync).
            if (! $this->certificadoVigente($unidade)) {
                $user?->notify(new RecuperacaoConcluida(
                    $parametro,
                    ResultadoRecuperacao::de(
                        ResultadoRecuperacao::BLOQUEADO,
                        0,
                        'Certificado e-CNPJ vencido ou ausente.',
                    ),
                ));

                return;
            }

            // Circuito aberto: nem tenta. Com tentativas restantes, reagenda
            // para depois do freio (não notifica ainda); na última, notifica.
            if ($guard->isOpen()) {
                if ($this->temTentativasRestantes()) {
                    // Freio aberto: espera fechar e MANTÉM a tentativa. Piso de
                    // 1 min evita republicação imediata se o freio estiver a
                    // ponto de fechar (minutosRestantesDoFreio() == 0).
                    $espera = max(60, $guard->minutosRestantesDoFreio() * 60);
                    $this->reagendar(now()->addSeconds($espera), consomeTentativa: false);

                    return;
                }

                $user?->notify(new RecuperacaoConcluida(
                    $parametro,
                    ResultadoRecuperacao::de(
                        ResultadoRecuperacao::BLOQUEADO,
                        0,
                        'Circuito da SEFAZ aberto.',
                    ),
                ));

                return;
            }

            $resultado = $this->chave !== null
                ? $recuperacao->porChave($unidade, $this->chave)
                : $recuperacao->porNsu($unidade, (int) $this->nsu);

            // Desfecho retentável e ainda há tentativas: reagenda sem notificar.
            // `bloqueado` mantém a MESMA tentativa (não consome) e espera o freio.
            if ($this->deveReagendar($resultado) && $this->temTentativasRestantes()) {
                $this->reagendar(
                    now()->addSeconds($this->delayDoReagendamento($resultado, $guard)),
                    consomeTentativa: $resultado->status !== ResultadoRecuperacao::BLOQUEADO,
                );

                return;
            }

            // Desfecho final: consome a fila pelo caminho NORMAL (varredura
            // incremental), que avança o cursor legítimo e importa o que ainda
            // estiver pendente. `SyncNfeJob` é `ShouldBeUnique` por unidade e
            // revalida certificado/circuito — sem risco de rajada.
            SyncNfeJob::dispatch($unidade->id);

            $user?->notify(new RecuperacaoConcluida($parametro, $resultado, $this->tentativa));
        });
    }

    /**
     * A unidade possui certificado e-CNPJ ativo e VIGENTE?
     */
    private function certificadoVigente(Unidade $unidade): bool
    {
        $certificado = $unidade->certificates()
            ->where('ativo', true)
            ->latest('id')
            ->first();

        return $certificado !== null
            && $certificado->valido_ate !== null
            && $certificado->valido_ate->isFuture();
    }
}

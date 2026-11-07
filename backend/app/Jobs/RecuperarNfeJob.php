<?php

namespace App\Jobs;

use App\Domain\Sefaz\RecuperacaoService;
use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Domain\Sefaz\SefazGuard;
use App\Models\Unidade;
use App\Models\User;
use App\Notifications\RecuperacaoConcluida;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Recupera MANUALMENTE uma NF-e que já passou pela fila do DistDFe.
 *
 * - Por chave de acesso (`consChNFe`) ou por NSU específico (`consNSU`).
 * - `ShouldBeUnique` por unidade + parâmetro: cliques repetidos não geram
 *   consultas simultâneas (protege contra consumo indevido/656).
 * - Revalida certificado vigente e circuit breaker (igual ao `SyncNfeJob`).
 * - Roda no `TenantContext` da unidade e NOTIFICA o solicitante ao final.
 */
class RecuperarNfeJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly int $unidadeId,
        public readonly int $userId,
        public readonly ?string $chave = null,
        public readonly ?int $nsu = null,
        public readonly int $tentativa = 1,
    ) {
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
     */
    private function temTentativasRestantes(): bool
    {
        return $this->tentativa < count((array) config('nfegov.recuperacao.backoff_minutes', [30, 120, 360]));
    }

    /**
     * Despacha uma nova execução deste job para depois do delay informado.
     * `bloqueado` mantém a MESMA tentativa (freio não consome tentativa);
     * os demais avançam o contador.
     */
    private function reagendar(\DateTimeInterface $quando, bool $consomeTentativa = true): void
    {
        $proxima = $consomeTentativa ? $this->tentativa + 1 : $this->tentativa;

        self::dispatch($this->unidadeId, $this->userId, $this->chave, $this->nsu, $proxima)
            ->delay($quando);
    }

    public function handle(RecuperacaoService $recuperacao, SefazGuard $guard): void
    {
        try {
            $this->executar($recuperacao, $guard);
        } finally {
            // Libera a idempotência: o usuário pode tentar de novo após o fim.
            Cache::forget($this->chaveIdempotencia());
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
                    // Freio aberto: espera fechar e MANTÉM a tentativa.
                    $this->reagendar(
                        now()->addSeconds($guard->minutosRestantesDoFreio() * 60),
                        consomeTentativa: false,
                    );

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

            $user?->notify(new RecuperacaoConcluida($parametro, $resultado));
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

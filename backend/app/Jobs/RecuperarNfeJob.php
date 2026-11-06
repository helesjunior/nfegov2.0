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

/**
 * Recupera MANUALMENTE uma NF-e que já passou pela fila do DistDFe.
 *
 * - Por chave de acesso (`consChNFe`) ou por NSU específico (`consNSU`).
 * - `ShouldBeUnique` por unidade + parâmetro: cliques repetidos não geram
 *   consultas simultâneas (protege contra consumo indevido/656).
 * - Revalida certificado vigente e circuit breaker (igual ao `SyncNfeJob`).
 * - Roda no `TenantContext` da unidade e NOTIFICA o solicitante ao final.
 */
class RecuperarNfeJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $unidadeId,
        public readonly int $userId,
        public readonly ?string $chave = null,
        public readonly ?int $nsu = null,
    ) {
        $this->onQueue(config('nfegov.sefaz.queue', 'sefaz'));
    }

    /**
     * Unicidade por unidade + parâmetro: uma busca idêntica por vez.
     */
    public function uniqueId(): string
    {
        $parametro = $this->chave !== null
            ? 'chave-'.sha1(preg_replace('/\D/', '', $this->chave))
            : 'nsu-'.$this->nsu;

        return 'recuperar-unidade-'.$this->unidadeId.'-'.$parametro;
    }

    public function handle(RecuperacaoService $recuperacao, SefazGuard $guard): void
    {
        $unidade = Unidade::withoutGlobalScopes()->find($this->unidadeId);

        if (! $unidade) {
            return;
        }

        TenantContext::runAs($unidade->tenant_id, function () use ($unidade, $recuperacao, $guard) {
            $user = User::find($this->userId);

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

            // Circuito aberto: nem tenta.
            if ($guard->isOpen()) {
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
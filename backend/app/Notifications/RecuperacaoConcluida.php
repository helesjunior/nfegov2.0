<?php

namespace App\Notifications;

use App\Domain\Sefaz\ResultadoRecuperacao;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Avisa o usuário sobre o desfecho de uma recuperação manual de NF-e.
 *
 * Canal APENAS `database` (aparece na central de notificações da SPA). Não tem
 * `caminho`: não é uma exportação com download, é um aviso informativo.
 */
class RecuperacaoConcluida extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $parametro,
        public readonly ResultadoRecuperacao $resultado,
        public readonly int $tentativas = 1,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'recuperacao',
            'mensagem' => $this->mensagem(),
            'status' => $this->resultado->status,
        ];
    }

    private function mensagem(): string
    {
        $esgotado = $this->tentativas > 1
            ? sprintf('Após %d tentativas automáticas, ', $this->tentativas)
            : '';

        return match ($this->resultado->status) {
            ResultadoRecuperacao::ENCONTRADO => sprintf(
                'Busca de %s concluída: %d nota(s) recuperada(s) e disponível(is) para consulta.',
                $this->parametro,
                $this->resultado->docsCount,
            ),
            ResultadoRecuperacao::NAO_ENCONTRADO => sprintf(
                '%sbusca de %s não teve sucesso: %s '
                .'Se a nota é recente, o emitente pode ainda não tê-la disponibilizado à SEFAZ; '
                .'caso contrário, confirme o destinatário com o emitente.',
                $esgotado,
                $this->parametro,
                $this->resultado->motivo ?? 'a SEFAZ não devolveu nenhum documento.',
            ),
            ResultadoRecuperacao::BLOQUEADO => sprintf(
                'Busca de %s não realizada: a SEFAZ está temporariamente indisponível (freio de consumo). Tente novamente mais tarde.',
                $this->parametro,
            ),
            default => sprintf(
                '%sbusca de %s falhou: %s',
                $esgotado,
                $this->parametro,
                $this->resultado->motivo ?? 'erro inesperado.',
            ),
        };
    }
}

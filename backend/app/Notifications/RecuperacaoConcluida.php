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
        return match ($this->resultado->status) {
            ResultadoRecuperacao::ENCONTRADO => sprintf(
                'Busca de %s concluída: %d nota(s) recuperada(s) e disponível(is) para consulta.',
                $this->parametro,
                $this->resultado->docsCount,
            ),
            ResultadoRecuperacao::NAO_ENCONTRADO => sprintf(
                'Busca de %s concluída, mas nenhum documento foi encontrado. '
                .'Se você acabou de enviar a Ciência da Operação, o XML pode levar alguns minutos para ficar disponível — tente novamente.',
                $this->parametro,
            ),
            ResultadoRecuperacao::BLOQUEADO => sprintf(
                'Busca de %s não realizada: a SEFAZ está temporariamente indisponível (freio de consumo). Tente novamente mais tarde.',
                $this->parametro,
            ),
            default => sprintf(
                'Busca de %s falhou: %s',
                $this->parametro,
                $this->resultado->motivo ?? 'erro inesperado.',
            ),
        };
    }
}
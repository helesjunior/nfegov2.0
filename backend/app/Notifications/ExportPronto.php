<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa que uma exportação assíncrona terminou e está pronta para download.
 *
 * Guarda apenas o CAMINHO no disco privado (não uma URL permanente): o acesso
 * se dá por rota autenticada, que gera o link assinado na hora.
 */
class ExportPronto extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $caminho,
        public readonly string $formato,
        public readonly int $total,
    ) {
    }

    /**
     * Canais: banco (notificação na SPA) e e-mail.
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sua exportação de NF-e está pronta')
            ->line("A exportação em {$this->formato} foi concluída com {$this->total} registro(s).")
            ->line('Acesse o sistema para baixar o arquivo.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'caminho' => $this->caminho,
            'formato' => $this->formato,
            'total' => $this->total,
        ];
    }
}
<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Avisa o usuário sobre o desfecho da busca manual de uma NFS-e por chave.
 *
 * Canal APENAS `database` (central de notificações da SPA). Usa `tipo =
 * recuperacao`, o mesmo da busca manual de NF-e, para a SPA mostrar a
 * `mensagem` como está.
 */
class NfseBuscaConcluida extends Notification
{
    use Queueable;

    public const ENCONTRADA = 'encontrado';
    public const NAO_ENCONTRADA = 'nao_encontrado';
    public const BLOQUEADA = 'bloqueado';
    public const ERRO = 'erro';

    public function __construct(
        public readonly string $chave,
        public readonly string $status,
        public readonly ?string $detalhe = null,
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
            'status' => $this->status,
        ];
    }

    private function mensagem(): string
    {
        $alvo = 'da NFS-e '.$this->chave;

        return match ($this->status) {
            self::ENCONTRADA => "Busca {$alvo} concluída: a nota está disponível para consulta.",
            self::NAO_ENCONTRADA => "A busca {$alvo} não encontrou a nota no Ambiente de Dados Nacional"
                .($this->detalhe ? " ({$this->detalhe})" : '').'. '
                .'Confira a chave e se a unidade é tomadora, prestadora ou intermediária; '
                .'notas de municípios que ainda não compartilham com o ambiente nacional não aparecem.',
            self::BLOQUEADA => "Busca {$alvo} não realizada: o Ambiente de Dados Nacional está temporariamente indisponível. Tente novamente mais tarde.",
            default => "A busca {$alvo} falhou: ".($this->detalhe ?? 'erro inesperado.'),
        };
    }
}

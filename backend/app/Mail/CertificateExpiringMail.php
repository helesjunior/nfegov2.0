<?php

namespace App\Mail;

use App\Models\Certificate;
use App\Models\Unidade;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Alerta de vencimento de certificado e-CNPJ.
 *
 * Enviado aos administradores do tenant quando o certificado de uma unidade
 * cruza um dos marcos (60/30/15/7/1 dias). NUNCA inclui a senha nem o PFX.
 */
class CertificateExpiringMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Certificate $certificate,
        public readonly Unidade $unidade,
        public readonly int $diasRestantes,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '[NFeGov] Certificado e-CNPJ vence em %d dia(s) — %s',
                $this->diasRestantes,
                $this->unidade->nome,
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.certificate-expiring',
            with: [
                'unidade' => $this->unidade,
                'certificate' => $this->certificate,
                'diasRestantes' => $this->diasRestantes,
            ],
        );
    }
}
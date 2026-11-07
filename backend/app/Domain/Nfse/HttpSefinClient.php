<?php

namespace App\Domain\Nfse;

use App\Models\Unidade;
use RuntimeException;

/**
 * Cliente HTTP da SEFIN Nacional, com mTLS (`MtlsCurl`).
 *
 * O corpo é `{"pedidoRegistroEventoXmlGZipB64": "<gzip+base64 do pedido>"}`.
 */
class HttpSefinClient implements SefinClient
{
    public function __construct(private readonly MtlsCurl $mtls) {}

    public function registrarEvento(Unidade $unidade, string $chave, string $pedidoXmlAssinado): RespostaSefin
    {
        $chave = preg_replace('/\D/', '', $chave);

        if (strlen($chave) !== 50) {
            throw new RuntimeException('A chave da NFS-e deve ter 50 dígitos.');
        }

        $base = rtrim((string) config('nfegov.adn.sefin_url.'.((int) ($unidade->tp_amb ?? 2) === 1 ? 1 : 2)), '/');

        $corpo = json_encode([
            'pedidoRegistroEventoXmlGZipB64' => base64_encode(gzencode($pedidoXmlAssinado)),
        ]);

        [$http, $resposta] = $this->mtls->enviar(
            'POST',
            $base.'/nfse/'.$chave.'/eventos',
            $this->mtls->certificado($unidade),
            $corpo,
            ['Accept: application/json', 'Content-Type: application/json'],
        );

        return RespostaSefin::deCorpo($http, $resposta);
    }
}

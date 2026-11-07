<?php

namespace App\Domain\Nfse;

use App\Models\Unidade;

/**
 * Comunicação com a SEFIN Nacional (registro de eventos da NFS-e).
 *
 * Isola o domínio do HTTP, permitindo um fake nos testes. Falha de conexão
 * lança exceção; respostas da SEFIN, inclusive rejeições, voltam como
 * `RespostaSefin`.
 */
interface SefinClient
{
    /**
     * `POST /nfse/{chave}/eventos` com o pedido de registro de evento já
     * ASSINADO (XML com declaração UTF-8).
     */
    public function registrarEvento(Unidade $unidade, string $chave, string $pedidoXmlAssinado): RespostaSefin;
}

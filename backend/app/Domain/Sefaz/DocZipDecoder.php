<?php

namespace App\Domain\Sefaz;

use RuntimeException;

/**
 * Decodifica o conteúdo de um `docZip` do distDFe.
 *
 * O distDFe entrega cada documento como base64 de um GZIP contendo o XML.
 */
class DocZipDecoder
{
    public function decode(string $base64gz): string
    {
        $gzip = base64_decode($base64gz, true);

        if ($gzip === false) {
            throw new RuntimeException('Conteúdo docZip não é um base64 válido.');
        }

        $xml = @gzdecode($gzip);

        if ($xml === false) {
            throw new RuntimeException('Conteúdo docZip não é um GZIP válido.');
        }

        return $xml;
    }
}
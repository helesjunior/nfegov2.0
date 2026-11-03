<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;

/**
 * Download do XML completo de uma NF-e pela chave de acesso.
 *
 * Delega ao `SefazClient`; devolve o XML quando disponível (`cStat 138`) ou
 * `null` caso contrário (ex.: nota sem permissão de download, ainda não
 * processada, ou ciência recém-enviada aguardando propagação).
 */
class DownloadService
{
    public function __construct(private readonly SefazClient $client)
    {
    }

    /**
     * Baixa o XML completo da chave. `null` quando indisponível.
     */
    public function porChave(Unidade $unidade, string $chave): ?string
    {
        $chave = preg_replace('/\D/', '', $chave);

        $xml = $this->client->baixarPorChave($unidade, $chave);

        if (blank($xml)) {
            return null;
        }

        return $xml;
    }
}
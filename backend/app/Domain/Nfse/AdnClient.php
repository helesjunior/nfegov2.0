<?php

namespace App\Domain\Nfse;

use App\Models\Unidade;

/**
 * Comunicação com a API de contribuintes do Ambiente de Dados Nacional (ADN).
 *
 * Isola o domínio do HTTP, permitindo um fake nos testes. Falha de transporte
 * (timeout, TLS) lança exceção; respostas do ADN, inclusive erros, voltam
 * como `RespostaAdn`.
 */
interface AdnClient
{
    /**
     * `GET /DFe/{ultimoNsu}?lote=true&cnpjConsulta=` — até 50 documentos
     * DEPOIS do NSU informado (mesma semântica do `ultNSU` da NF-e).
     */
    public function distribuicao(Unidade $unidade, int $ultimoNsu): RespostaAdn;

    /**
     * `GET /NFSe/{chave}/Eventos` — a NFS-e da chave e os eventos dela.
     */
    public function porChave(Unidade $unidade, string $chave): RespostaAdn;
}

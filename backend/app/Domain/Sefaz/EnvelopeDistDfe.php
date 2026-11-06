<?php

namespace App\Domain\Sefaz;

/**
 * Interpreta o `retDistDFeInt` (resposta do distDFe).
 *
 * Compartilhado pelo laço incremental (`DistDfeService`) e pela recuperação
 * pontual (`RecuperacaoService`): ambos recebem o mesmo envelope, seja da
 * consulta por fila (`ultNSU`), por NSU específico (`consNSU`) ou por chave
 * (`consChNFe`).
 */
class EnvelopeDistDfe
{
    /**
     * @return array{cStat:string, xMotivo:string, ultNSU:int, maxNSU:int, docs:array<int, array{nsu:int, schema:string, conteudo:string}>}
     */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument;
        @$dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $docs = [];
        foreach ($xp->query('//nfe:loteDistDFeInt/nfe:docZip') as $node) {
            $docs[] = [
                'nsu' => (int) $node->attributes->getNamedItem('NSU')?->nodeValue,
                'schema' => (string) $node->attributes->getNamedItem('schema')?->nodeValue,
                'conteudo' => trim($node->nodeValue),
            ];
        }

        return [
            'cStat' => (string) $xp->query('//nfe:cStat')->item(0)?->nodeValue,
            'xMotivo' => (string) $xp->query('//nfe:xMotivo')->item(0)?->nodeValue,
            'ultNSU' => (int) $xp->query('//nfe:ultNSU')->item(0)?->nodeValue,
            'maxNSU' => (int) $xp->query('//nfe:maxNSU')->item(0)?->nodeValue,
            'docs' => $docs,
        ];
    }
}

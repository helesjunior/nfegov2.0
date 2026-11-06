<?php

namespace App\Domain\Nfe;

use App\Domain\Sefaz\DocZipDecoder;
use App\Domain\Sefaz\DownloadService;
use App\Domain\Sefaz\ManifestacaoService;
use App\Models\Nsu;
use App\Models\Unidade;

/**
 * Transforma UM documento do distDFe (docZip) em nota no banco.
 *
 * Compartilhado pelo laço incremental (`DistDfeService`) e pela recuperação
 * pontual (`RecuperacaoService`). NÃO mexe no cursor `Nsu` da unidade: quem
 * controla o cursor é o laço da fila; aqui só se processa o documento.
 *
 * - `resNFe` (resumo): importa o resumo, envia a Ciência da Operação (210210)
 *   e reconsulta o XML completo para importar.
 * - `procNFe`/`nfeProc`: importa direto.
 * - Demais schemas (ex.: `resEvento`): ignorados nesta versão.
 */
class DocumentoProcessor
{
    public function __construct(
        private readonly DocZipDecoder $decoder,
        private readonly NfeImporter $importer,
        private readonly ManifestacaoService $manifestacao,
        private readonly DownloadService $download,
    ) {}

    /**
     * @param  array{nsu:int, schema:string, conteudo:string}  $doc
     */
    public function processar(Unidade $unidade, ?Nsu $nsu, array $doc): void
    {
        $xml = $this->decoder->decode($doc['conteudo']);

        if ($doc['schema'] === 'resNFe') {
            $this->importer->importResumo($unidade, $nsu, $xml);

            // Ciência da operação + reconsulta do XML completo.
            $chave = $this->chaveDoResumo($xml);
            if ($chave) {
                $this->manifestacao->ciencia($unidade, $chave);
                $completo = $this->download->porChave($unidade, $chave);

                if ($completo) {
                    $this->importer->import($unidade, $nsu, $completo);
                }
            }

            return;
        }

        // procNFe / nfeProc: documentos de NF-e são importados.
        if (in_array($doc['schema'], ['procNFe', 'nfeProc'], true)) {
            $this->importer->import($unidade, $nsu, $xml);
        }
    }

    private function chaveDoResumo(string $xml): ?string
    {
        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $chave = preg_replace('/\D/', '', (string) $xp->query('//nfe:chNFe')->item(0)?->nodeValue);

        return strlen($chave) === 44 ? $chave : null;
    }
}
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

        // Em PRODUÇÃO a SEFAZ rotula o schema com sufixo de versão/arquivo
        // (ex.: `procNFe_v4.00.xsd`, `resNFe_v1.01.xsd`); em testes vem o nome
        // "puro" (`procNFe`). Normalizamos para comparar de forma estável.
        $schema = $this->schemaBase($doc['schema']);

        if ($schema === 'resNFe') {
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
        if (in_array($schema, ['procNFe', 'nfeProc'], true)) {
            $this->importer->import($unidade, $nsu, $xml);
        }
    }

    /**
     * Reduz o rótulo do schema à sua raiz, descartando versão e extensão:
     * `procNFe_v4.00.xsd` → `procNFe`; `resNFe` → `resNFe`.
     */
    private function schemaBase(string $schema): string
    {
        // Remove extensão `.xsd` e o sufixo `_vX.YZ`.
        $schema = preg_replace('/\.xsd$/i', '', $schema);
        $schema = preg_replace('/_v\d+.*$/i', '', $schema);

        return $schema;
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

<?php

namespace App\Domain\Nfse;

use App\Domain\Sefaz\DocZipDecoder;
use App\Models\Unidade;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava UM documento da fila do ADN (NFS-e ou evento) para a unidade.
 *
 * Compartilhado pelo laço da fila (`DistNfseService`) e pela busca manual por
 * chave (`RecuperarNfseJob`). Não mexe no cursor NSU: quem controla o cursor é
 * o laço da fila.
 */
class NfseDocumentoProcessor
{
    public function __construct(
        private readonly DocZipDecoder $decoder,
        private readonly NfseImporter $importer,
    ) {}

    /**
     * Devolve um aviso quando o documento não pôde ser importado, ou `null`.
     *
     * @param  array{nsu:int, chave:string, tipo_documento:string, tipo_evento:?string, arquivo_xml:string}  $documento
     */
    public function processar(Unidade $unidade, array $documento): ?string
    {
        try {
            $xml = $this->decoder->decode($documento['arquivo_xml']);

            match ($documento['tipo_documento']) {
                'NFSE' => $this->importer->importarNfse($unidade, $xml, $documento['nsu']),
                'EVENTO' => $this->importer->importarEvento($unidade, $xml, $documento['nsu'], $documento['tipo_evento']),
                default => null, // DPS, pedidos e CNC não são guardados.
            };

            return null;
        } catch (Throwable $e) {
            Log::warning('NFS-e: documento do ADN ignorado.', [
                'unidade_id' => $unidade->id,
                'nsu' => $documento['nsu'],
                'chave' => $documento['chave'],
                'erro' => $e->getMessage(),
            ]);

            return "NSU {$documento['nsu']}: {$e->getMessage()}";
        }
    }
}

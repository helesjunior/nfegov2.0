<?php

namespace App\Jobs;

use App\Domain\Nfe\NfeQuery;
use App\Models\User;
use App\Notifications\ExportPronto;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Gera a exportação de metadados de NF-e (CSV ou XLSX) de forma assíncrona.
 *
 * Mantém MEMÓRIA CONSTANTE: percorre as NF-e com `chunkById` e escreve linha a
 * linha no arquivo (streaming), nunca carregando tudo em memória.
 *
 * Ao terminar, grava o arquivo no disco privado e NOTIFICA o solicitante.
 */
class ExportNfeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly array $filtros = [],
        public readonly string $formato = 'csv',
    ) {
        $this->onQueue('default');
    }

    public function handle(NfeQuery $query): void
    {
        TenantContext::runAs($this->tenantId, function () use ($query) {
            $caminho = sprintf('exports/%d/%s.%s', $this->tenantId, bin2hex(random_bytes(8)), $this->formato);

            Storage::disk($this->disco())->makeDirectory(dirname($caminho));
            $caminhoAbsoluto = Storage::disk($this->disco())->path($caminho);

            $total = $this->formato === 'xlsx'
                ? $this->escreverXlsx($query, $caminhoAbsoluto)
                : $this->escreverCsv($query, $caminhoAbsoluto);

            $this->notificar($caminho, $total);
        });
    }

    /**
     * Escreve CSV linha a linha (streaming nativo).
     */
    private function escreverCsv(NfeQuery $query, string $caminhoAbsoluto): int
    {
        $handle = fopen($caminhoAbsoluto, 'w');
        fputcsv($handle, $this->cabecalho());
        $total = 0;

        $query->build($this->filtros)
            ->orderBy('id')
            ->chunkById(500, function ($nfes) use ($handle, &$total) {
                foreach ($nfes as $nfe) {
                    fputcsv($handle, $this->linha($nfe));
                    $total++;
                }
            });

        fclose($handle);

        return $total;
    }

    /**
     * Escreve XLSX com openspout (streaming; não acumula tudo em memória).
     */
    private function escreverXlsx(NfeQuery $query, string $caminhoAbsoluto): int
    {
        $writer = new XlsxWriter();
        $writer->openToFile($caminhoAbsoluto);
        $writer->addRow(Row::fromValues($this->cabecalho()));
        $total = 0;

        $query->build($this->filtros)
            ->orderBy('id')
            ->chunkById(500, function ($nfes) use ($writer, &$total) {
                foreach ($nfes as $nfe) {
                    $writer->addRow(Row::fromValues($this->linha($nfe)));
                    $total++;
                }
            });

        $writer->close();

        return $total;
    }

    /**
     * @return array<int, string>
     */
    private function cabecalho(): array
    {
        return ['Chave', 'Numero', 'Serie', 'Modelo', 'Unidade', 'Emitente CNPJ', 'Emitente', 'Valor total', 'Data emissao', 'Estado'];
    }

    /**
     * @return array<int, string|float|null>
     */
    private function linha($nfe): array
    {
        return [
            $nfe->chave,
            (string) $nfe->numero,
            (string) $nfe->serie,
            (string) $nfe->modelo,
            (string) $nfe->unidade_id,
            (string) $nfe->emitente_cnpj,
            (string) $nfe->emitente_nome,
            $nfe->valor_total !== null ? (float) $nfe->valor_total : null,
            $nfe->data_emissao?->toIso8601String(),
            (string) $nfe->estado,
        ];
    }

    private function notificar(string $caminho, int $total): void
    {
        $user = User::withoutGlobalScopes()->find($this->userId);

        $user?->notify(new ExportPronto($caminho, $this->formato, $total));
    }

    private function disco(): string
    {
        return config('nfegov.storage.disk', 'local');
    }
}
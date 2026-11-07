<?php

namespace App\Jobs;

use App\Domain\Nfse\NfseQuery;
use App\Models\Nfse;
use App\Models\User;
use App\Notifications\ExportPronto;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exportação de metadados de NFS-e (CSV ou XLSX), assíncrona e em streaming
 * (`chunkById`), como a da NF-e. Notifica o solicitante ao terminar.
 */
class ExportNfseJob implements ShouldQueue
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

    public function handle(NfseQuery $query): void
    {
        TenantContext::runAs($this->tenantId, function () use ($query) {
            $disco = Storage::disk(config('nfegov.storage.disk', 'local'));
            $caminho = sprintf('exports/%d/%s.%s', $this->tenantId, bin2hex(random_bytes(8)), $this->formato);
            $disco->makeDirectory(dirname($caminho));
            $absoluto = $disco->path($caminho);

            $total = 0;
            $consulta = $query->build($this->filtros)->with(NfseQuery::comUnidade())->orderBy('id');

            if ($this->formato === 'xlsx') {
                $writer = new XlsxWriter;
                $writer->openToFile($absoluto);
                $writer->addRow(Row::fromValues($this->cabecalho()));
                $consulta->chunkById(500, function ($nfses) use ($writer, &$total) {
                    foreach ($nfses as $nfse) {
                        $writer->addRow(Row::fromValues($this->linha($nfse)));
                        $total++;
                    }
                });
                $writer->close();
            } else {
                $handle = fopen($absoluto, 'w');
                fputcsv($handle, $this->cabecalho());
                $consulta->chunkById(500, function ($nfses) use ($handle, &$total) {
                    foreach ($nfses as $nfse) {
                        fputcsv($handle, $this->linha($nfse));
                        $total++;
                    }
                });
                fclose($handle);
            }

            User::withoutGlobalScopes()->find($this->userId)
                ?->notify(new ExportPronto($caminho, $this->formato, $total, 'NFS-e'));
        });
    }

    /**
     * @return array<int, string>
     */
    private function cabecalho(): array
    {
        return [
            'Chave', 'Numero', 'Papel', 'Situacao', 'Unidade', 'Unidade CNPJ',
            'Prestador documento', 'Prestador', 'Tomador documento', 'Tomador',
            'Municipio emissor', 'Data emissao', 'Competencia', 'Codigo servico',
            'Servico', 'Valor servico', 'Valor liquido', 'Valor ISS', 'ISS retido',
        ];
    }

    /**
     * @return array<int, string|float|null>
     */
    private function linha(Nfse $nfse): array
    {
        return [
            $nfse->chave,
            (string) $nfse->numero,
            (string) $nfse->papel,
            (string) $nfse->situacao,
            (string) ($nfse->unidade?->nome_resumido ?: $nfse->unidade?->nome ?: $nfse->unidade_id),
            (string) $nfse->unidade?->cnpj,
            (string) $nfse->prestador_documento,
            (string) $nfse->prestador_nome,
            (string) $nfse->tomador_documento,
            (string) $nfse->tomador_nome,
            (string) $nfse->municipio_emissor_nome,
            $nfse->data_emissao?->toIso8601String(),
            $nfse->competencia?->format('Y-m-d'),
            (string) $nfse->codigo_servico,
            (string) $nfse->servico_nome,
            $nfse->valor_servico !== null ? (float) $nfse->valor_servico : null,
            $nfse->valor_liquido !== null ? (float) $nfse->valor_liquido : null,
            $nfse->valor_iss !== null ? (float) $nfse->valor_iss : null,
            $nfse->iss_retido ? 'sim' : 'nao',
        ];
    }
}

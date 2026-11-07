<?php

namespace App\Jobs;

use App\Domain\Nfse\NfseQuery;
use App\Models\NfseEvento;
use App\Models\User;
use App\Notifications\ExportPronto;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * ZIP com os XMLs das NFS-e filtradas, uma pasta por unidade (CNPJ), com os
 * XMLs dos eventos de cada nota em `eventos/`. Percorre em blocos e adiciona
 * os arquivos direto do disco, sem carregá-los em memória. Os XMLs são
 * imutáveis: o ZIP é só um empacotamento de cópias.
 */
class ExportNfseXmlJob implements ShouldQueue
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
    ) {
        $this->onQueue('default');
    }

    public function handle(NfseQuery $query): void
    {
        TenantContext::runAs($this->tenantId, function () use ($query) {
            $disco = Storage::disk(config('nfegov.storage.disk', 'local'));

            $caminho = sprintf('exports/%d/%s.zip', $this->tenantId, bin2hex(random_bytes(8)));
            $disco->makeDirectory(dirname($caminho));

            $zip = new ZipArchive;
            $zip->open($disco->path($caminho), ZipArchive::CREATE | ZipArchive::OVERWRITE);

            $total = 0;

            $query->build($this->filtros)
                ->with(NfseQuery::comUnidade())
                ->whereNotNull('xml_path')
                ->orderBy('id')
                ->chunkById(200, function ($nfses) use ($zip, $disco, &$total) {
                    foreach ($nfses as $nfse) {
                        if (! $disco->exists($nfse->xml_path)) {
                            continue;
                        }

                        $pasta = $this->pasta($nfse);
                        $zip->addFile($disco->path($nfse->xml_path), $pasta.'/'.$nfse->chave.'.xml');
                        $total++;

                        NfseEvento::where('nfse_id', $nfse->id)->whereNotNull('xml_path')->get()
                            ->each(function ($evento) use ($zip, $disco, $pasta) {
                                if ($disco->exists($evento->xml_path)) {
                                    $zip->addFile($disco->path($evento->xml_path), $pasta.'/eventos/'.basename($evento->xml_path));
                                }
                            });
                    }
                });

            $zip->close();

            User::withoutGlobalScopes()->find($this->userId)
                ?->notify(new ExportPronto($caminho, 'zip', $total, 'XMLs de NFS-e'));
        });
    }

    /**
     * Pasta da unidade dentro do ZIP: o CNPJ (só dígitos), ou o id se faltar.
     */
    private function pasta($nfse): string
    {
        $cnpj = preg_replace('/\D/', '', (string) $nfse->unidade?->cnpj);

        return $cnpj !== '' ? $cnpj : 'unidade-'.$nfse->unidade_id;
    }
}

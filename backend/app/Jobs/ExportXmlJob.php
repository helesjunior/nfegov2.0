<?php

namespace App\Jobs;

use App\Domain\Nfe\NfeQuery;
use App\Models\User;
use App\Notifications\ExportPronto;
use App\Support\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Gera um ZIP com os XMLs das NF-e filtradas (exportação em massa).
 *
 * Percorre as NF-e em blocos e adiciona cada XML ao ZIP diretamente do disco
 * (sem carregar todos em memória). Ao final, notifica o solicitante.
 *
 * Os XMLs são imutáveis: o ZIP é apenas um empacotamento de cópias.
 */
class ExportXmlJob implements ShouldQueue
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

    public function handle(NfeQuery $query): void
    {
        TenantContext::runAs($this->tenantId, function () use ($query) {
            $disco = Storage::disk($this->disco());

            $caminho = sprintf('exports/%d/%s.zip', $this->tenantId, bin2hex(random_bytes(8)));
            $disco->makeDirectory(dirname($caminho));
            $caminhoAbsoluto = $disco->path($caminho);

            $zip = new ZipArchive();
            $zip->open($caminhoAbsoluto, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            $total = 0;

            $query->build($this->filtros)
                ->whereNotNull('xml_path')
                ->orderBy('id')
                ->chunkById(200, function ($nfes) use ($zip, $disco, &$total) {
                    foreach ($nfes as $nfe) {
                        if (! $disco->exists($nfe->xml_path)) {
                            continue;
                        }

                        // Adiciona diretamente do caminho no disco (streaming).
                        $zip->addFile($disco->path($nfe->xml_path), $nfe->chave.'.xml');
                        $total++;
                    }
                });

            $zip->close();

            $user = User::withoutGlobalScopes()->find($this->userId);
            $user?->notify(new ExportPronto($caminho, 'zip', $total));
        });
    }

    private function disco(): string
    {
        return config('nfegov.storage.disk', 'local');
    }
}
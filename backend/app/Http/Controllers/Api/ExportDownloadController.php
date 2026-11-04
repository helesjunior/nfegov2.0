<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download dos arquivos de exportação (CSV/XLSX/ZIP).
 *
 * Segurança:
 * - a rota é ASSINADA (expira) e autenticada;
 * - o arquivo é resolvido SEMPRE dentro de `exports/{tenant_id}/` do tenant
 *   ativo — nunca se confia no nome recebido nem se permite travessia de
 *   diretório;
 * - o caminho físico é montado a partir do `basename`, e a existência é
 *   validada antes de servir.
 */
class ExportDownloadController extends Controller
{
    public function __invoke(Request $request, string $arquivo): StreamedResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Só o nome do arquivo é aceito — descarta qualquer diretório.
        $nome = basename($arquivo);

        // Rejeita vazio, pontos de navegação e separadores residuais.
        abort_if($nome === '' || $nome === '.' || $nome === '..', 404);
        abort_if(str_contains($nome, '/') || str_contains($nome, '\\'), 404);

        $caminho = "exports/{$tenantId}/{$nome}";

        abort_unless(Storage::disk(config('nfegov.storage.disk', 'local'))->exists($caminho), 404);

        return Storage::disk(config('nfegov.storage.disk', 'local'))
            ->download($caminho, $nome, [
                'Content-Type' => 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
            ]);
    }
}
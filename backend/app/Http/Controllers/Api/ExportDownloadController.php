<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TenantAtivoResolver;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download dos arquivos de exportação (CSV/XLSX/ZIP).
 *
 * Segurança:
 * - a rota é ASSINADA (expira) e autenticada;
 * - o tenant vem no link assinado (ou, na falta dele, é o tenant ativo) e o
 *   usuário precisa ter acesso a ele: vínculo ativo ou super-admin;
 * - o arquivo é resolvido SEMPRE dentro de `exports/{tenant_id}/` — nunca se
 *   confia no nome recebido nem se permite travessia de diretório;
 * - o caminho físico é montado a partir do `basename`, e a existência é
 *   validada antes de servir.
 */
class ExportDownloadController extends Controller
{
    public function __construct(private readonly TenantAtivoResolver $resolver)
    {
    }

    public function __invoke(Request $request, string $arquivo): StreamedResponse
    {
        $tenantId = $request->filled('tenant')
            ? (int) $request->query('tenant')
            : TenantContext::id();

        abort_if($tenantId === null || ! $this->podeAcessar($request->user(), $tenantId), 404);

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

    private function podeAcessar(User $user, int $tenantId): bool
    {
        if ($this->resolver->podeTodas($user)) {
            return true;
        }

        return $this->resolver->disponiveis($user)->contains('id', $tenantId);
    }
}

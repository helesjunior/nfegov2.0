<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNfeRequest;
use App\Jobs\ExportNfeJob;
use App\Jobs\ExportXmlJob;
use App\Models\Concerns\Auditable;
use App\Models\Nfe;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Exportações assíncronas.
 *
 * - `POST /api/nfes/export`     → CSV/XLSX de metadados.
 * - `POST /api/nfes/export-xml` → ZIP com os XMLs.
 *
 * A resposta é imediata (202): o trabalho pesado roda em job e o usuário é
 * notificado quando o arquivo está pronto. Os filtros são os mesmos da
 * listagem (`IndexNfeRequest`), garantindo consistência entre tela e export.
 */
class ExportController extends Controller
{
    use Auditable;

    public function store(IndexNfeRequest $request): JsonResponse
    {
        Gate::authorize('exportAny', Nfe::class);
        $tenantId = $this->tenantAtivo();

        $dados = $request->validated();
        $formato = $dados['formato'] ?? 'csv';

        // Só removemos 'formato'; os demais campos são filtros válidos.
        unset($dados['formato'], $dados['per_page'], $dados['page']);

        ExportNfeJob::dispatch(
            $tenantId,
            $request->user()->id,
            $dados,
            $formato,
        );

        $this->audit(
            'nfe.export.solicitado',
            ['formato' => $formato, 'filtros' => $dados],
            'tenant',
            $tenantId,
            $request->user(),
            $request,
        );

        return response()->json([
            'message' => 'A exportação foi agendada. Você será notificado quando estiver pronta.',
        ], 202);
    }

    public function xml(IndexNfeRequest $request): JsonResponse
    {
        Gate::authorize('exportAny', Nfe::class);
        $tenantId = $this->tenantAtivo();

        $dados = $request->validated();
        unset($dados['formato'], $dados['per_page'], $dados['page']);

        ExportXmlJob::dispatch(
            $tenantId,
            $request->user()->id,
            $dados,
        );

        $this->audit(
            'nfe.export_xml.solicitado',
            ['filtros' => $dados],
            'tenant',
            $tenantId,
            $request->user(),
            $request,
        );

        return response()->json([
            'message' => 'A exportação dos XMLs foi agendada. Você será notificado quando estiver pronta.',
        ], 202);
    }

    /**
     * A exportação usa a entidade ATIVA da sessão (a mesma da listagem), e
     * não o primeiro vínculo do usuário. No modo "Todas" do super-admin não
     * há entidade ativa: é preciso escolher uma antes de exportar.
     */
    private function tenantAtivo(): int
    {
        $tenantId = TenantContext::id();

        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => 'Selecione uma entidade para exportar.',
            ]);
        }

        return $tenantId;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Domain\Nfe\XmlStore;
use App\Domain\Nfse\NfseQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNfseRequest;
use App\Http\Resources\NfseResource;
use App\Domain\Nfse\Danfse;
use App\Jobs\ExportNfseJob;
use App\Jobs\ExportNfseXmlJob;
use App\Models\Concerns\Auditable;
use App\Models\Nfse;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFS-e: listagem com filtros, detalhe com eventos, XML (URL assinada) e
 * exportação CSV/XLSX. Mesmas regras da NF-e: escopo do tenant pelo global
 * scope, nota resolvida por chave + `?unidade_id=`, 404 fora do escopo.
 */
class NfseController extends Controller
{
    use Auditable;

    public function __construct(
        private readonly XmlStore $store,
        private readonly NfseQuery $query,
    ) {}

    public function index(IndexNfseRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Nfse::class);

        $filtros = $request->validated();
        $perPage = (int) ($filtros['per_page'] ?? 25);

        return NfseResource::collection(
            $this->query->ordenar($this->query->build($filtros), $filtros)
                ->with(NfseQuery::comUnidade())
                ->paginate($perPage)
        );
    }

    public function show(Request $request, string $chave): JsonResponse
    {
        $nfse = $this->resolver($request, $chave);

        Gate::authorize('view', $nfse);

        $resource = (new NfseResource($nfse->load(['eventos', ...NfseQuery::comUnidade()])))
            ->comPodeManifestar(
                $nfse->papel === Nfse::PAPEL_TOMADOR
                && $nfse->situacao === Nfse::SITUACAO_NORMAL
                && $nfse->manifestacao === null
                && Gate::allows('manifestar', $nfse)
            );

        if ($nfse->xml_path !== null && Gate::allows('downloadXml', $nfse)) {
            $resource->comXmlUrl(URL::temporarySignedRoute(
                'api.nfses.xml',
                now()->addMinutes(5),
                ['chave' => $nfse->chave, 'unidade_id' => $nfse->unidade_id],
            ));
        }

        return $resource->response();
    }

    /**
     * Entrega o XML. A rota exige assinatura válida (middleware `signed`).
     */
    public function xml(Request $request, string $chave): Response
    {
        $nfse = $this->resolver($request, $chave);

        Gate::authorize('downloadXml', $nfse);

        abort_if($nfse->xml_path === null, 404, 'XML indisponível.');

        $xml = $this->store->get($nfse->xml_path);

        $this->audit('nfse.xml.baixado', [], 'nfse', $nfse->chave, $request->user(), $request);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="'.$chave.'.xml"',
        ]);
    }

    /**
     * Exportação assíncrona (CSV/XLSX) com os mesmos filtros da listagem.
     */
    public function export(IndexNfseRequest $request): JsonResponse
    {
        Gate::authorize('exportAny', Nfse::class);

        $tenantId = TenantContext::id();
        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => 'Selecione uma entidade para exportar.',
            ]);
        }

        $dados = $request->validated();
        $formato = $dados['formato'] ?? 'csv';
        unset($dados['formato'], $dados['per_page'], $dados['page']);

        ExportNfseJob::dispatch($tenantId, $request->user()->id, $dados, $formato);

        $this->audit('nfse.export.solicitado', ['formato' => $formato, 'filtros' => $dados], 'tenant', $tenantId, $request->user(), $request);

        return response()->json([
            'message' => 'A exportação foi agendada. Você será notificado quando estiver pronta.',
        ], 202);
    }

    /**
     * ZIP assíncrono com os XMLs (e os eventos) das notas filtradas.
     */
    public function exportXml(IndexNfseRequest $request): JsonResponse
    {
        Gate::authorize('exportAny', Nfse::class);

        $tenantId = TenantContext::id();
        if ($tenantId === null) {
            throw ValidationException::withMessages([
                'tenant' => 'Selecione uma entidade para exportar.',
            ]);
        }

        $dados = $request->validated();
        unset($dados['formato'], $dados['per_page'], $dados['page']);

        ExportNfseXmlJob::dispatch($tenantId, $request->user()->id, $dados);

        $this->audit('nfse.export_xml.solicitado', ['filtros' => $dados], 'tenant', $tenantId, $request->user(), $request);

        return response()->json([
            'message' => 'A exportação dos XMLs foi agendada. Você será notificado quando estiver pronta.',
        ], 202);
    }

    /**
     * DANFSe (PDF) gerado na hora a partir do XML guardado, no leiaute da NT 008.
     */
    public function danfse(Request $request, string $chave, Danfse $danfse): Response
    {
        $nfse = $this->resolver($request, $chave);

        Gate::authorize('export', $nfse);

        abort_if($nfse->xml_path === null, 404, 'XML indisponível para gerar o DANFSe.');

        $pdf = $danfse->render($this->store->get($nfse->xml_path), $nfse->situacao);

        $this->audit('nfse.danfse.gerado', [], 'nfse', $nfse->chave, $request->user(), $request);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$chave.'.pdf"',
        ]);
    }

    /**
     * A mesma chave pode existir em mais de uma unidade da entidade.
     * `?unidade_id=` escolhe qual; sem ele, vale o registro mais antigo.
     */
    private function resolver(Request $request, string $chave): Nfse
    {
        $unidadeId = $request->query('unidade_id');

        return Nfse::where('chave', $chave)
            ->when(is_numeric($unidadeId), fn ($q) => $q->where('unidade_id', (int) $unidadeId))
            ->orderBy('id')
            ->firstOrFail();
    }
}

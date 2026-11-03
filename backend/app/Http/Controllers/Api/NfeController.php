<?php

namespace App\Http\Controllers\Api;

use App\Domain\Nfe\NfeQuery;
use App\Domain\Nfe\XmlStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNfeRequest;
use App\Http\Resources\NfeResource;
use App\Models\Concerns\Auditable;
use App\Models\Nfe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use NFePHP\DA\NFe\Danfe;
use Symfony\Component\HttpFoundation\Response;

/**
 * NF-e: listagem com filtros, detalhe, download do XML (URL assinada) e DANFE.
 *
 * Isolamento: o global scope (`BelongsToTenant`) já restringe toda consulta ao
 * tenant ativo. A NF-e é resolvida por CHAVE dentro do escopo, então uma nota
 * de outro tenant resulta em 404 (nunca revelamos a existência).
 *
 * O XML é imutável; o download é servido por URL ASSINADA e TEMPORÁRIA, o que
 * torna o acesso auditável e impede links permanentes.
 */
class NfeController extends Controller
{
    use Auditable;

    public function __construct(
        private readonly XmlStore $store,
        private readonly NfeQuery $query,
    ) {
    }

    public function index(IndexNfeRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Nfe::class);

        $filtros = $request->validated();
        $perPage = (int) ($filtros['per_page'] ?? 25);

        return NfeResource::collection(
            $this->query->build($filtros)->latest('data_emissao')->paginate($perPage)
        );
    }

    public function show(Request $request, string $chave): JsonResponse
    {
        $nfe = $this->resolver($chave);

        Gate::authorize('view', $nfe);

        $resource = (new NfeResource($nfe->load('itens')));

        // URL assinada/temporária do XML apenas para quem pode exportar.
        if ($nfe->xml_path !== null && Gate::allows('downloadXml', $nfe)) {
            $resource->comXmlUrl($this->urlAssinada($chave));
        }

        return $resource->response();
    }

    /**
     * Entrega o XML. Exige assinatura válida (a rota é assinada).
     */
    public function xml(Request $request, string $chave): Response
    {
        // A assinatura é verificada pelo middleware `signed` na rota.
        $nfe = $this->resolver($chave);

        Gate::authorize('downloadXml', $nfe);

        abort_if($nfe->xml_path === null, 404, 'XML indisponível.');

        $xml = $this->store->get($nfe->xml_path);

        // Download auditado: quem baixou o XML de qual chave.
        $this->audit('nfe.xml.baixado', [], 'nfe', $nfe->chave, $request->user(), $request);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'inline; filename="'.$chave.'.xml"',
        ]);
    }

    /**
     * Gera o DANFE (PDF) a partir do XML completo. Exige permissão `nfe.export`.
     */
    public function danfe(Request $request, string $chave): Response
    {
        $nfe = $this->resolver($chave);

        Gate::authorize('export', $nfe);

        abort_if($nfe->xml_path === null, 404, 'XML indisponível para gerar o DANFE.');

        $xml = $this->store->get($nfe->xml_path);

        $danfe = new Danfe($xml);
        $danfe->exibirTextoFatura(false);

        $pdf = $danfe->pdf();

        // Geração de DANFE também é um acesso sensível auditável.
        $this->audit('nfe.danfe.gerado', [], 'nfe', $nfe->chave, $request->user(), $request);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$chave.'.pdf"',
        ]);
    }

    /**
     * Resolve a NF-e por chave dentro do escopo do tenant. 404 se ausente.
     */
    private function resolver(string $chave): Nfe
    {
        return Nfe::where('chave', $chave)->firstOrFail();
    }

    /**
     * URL assinada e temporária para download do XML (5 minutos).
     */
    private function urlAssinada(string $chave): string
    {
        return URL::temporarySignedRoute(
            'api.nfes.xml',
            now()->addMinutes(5),
            ['chave' => $chave],
        );
    }
}
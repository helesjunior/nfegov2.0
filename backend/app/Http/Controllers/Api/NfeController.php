<?php

namespace App\Http\Controllers\Api;

use App\Domain\Nfe\XmlStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexNfeRequest;
use App\Http\Resources\NfeResource;
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
    public function __construct(private readonly XmlStore $store)
    {
    }

    public function index(IndexNfeRequest $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Nfe::class);

        $filtros = $request->validated();
        $perPage = (int) ($filtros['per_page'] ?? 25);

        $query = Nfe::query()
            ->when($filtros['de'] ?? null, fn ($q, $de) => $q->where('data_emissao', '>=', $de.' 00:00:00'))
            ->when($filtros['ate'] ?? null, fn ($q, $ate) => $q->where('data_emissao', '<=', $ate.' 23:59:59'))
            ->when($filtros['unidade_id'] ?? null, fn ($q, $u) => $q->where('unidade_id', $u))
            ->when($filtros['emitente_cnpj'] ?? null, fn ($q, $c) => $q->where('emitente_cnpj', $c))
            ->when($filtros['valor_min'] ?? null, fn ($q, $v) => $q->where('valor_total', '>=', $v))
            ->when($filtros['valor_max'] ?? null, fn ($q, $v) => $q->where('valor_total', '<=', $v))
            ->when($filtros['chave'] ?? null, fn ($q, $c) => $q->where('chave', $c))
            ->when($filtros['q'] ?? null, fn ($q, $termo) => $q->where(function ($sub) use ($termo) {
                $sub->where('emitente_nome', 'ilike', "%{$termo}%")
                    ->orWhere('numero', 'ilike', "%{$termo}%")
                    ->orWhere('chave', 'ilike', "%{$termo}%");
            }));

        return NfeResource::collection($query->latest('data_emissao')->paginate($perPage));
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
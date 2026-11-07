<?php

namespace App\Http\Controllers\Api;

use App\Domain\Nfse\AdnGuard;
use App\Domain\Sefaz\SefazGuard;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\Nfse;
use App\Models\SyncRun;
use App\Models\Unidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Painel: totais de NF-e e NFS-e, certificados vencendo e últimas sincronizações.
 *
 * Escopado ao tenant ativo. Também expõe o estado dos circuit breakers da SEFAZ
 * e do ADN (freios globais), para que o operador saiba que o consumo está
 * suspenso. Os números de NFS-e contam as notas RECEBIDAS (a unidade é a
 * tomadora); as emitidas e intermediadas ficam na lista, não no painel.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly SefazGuard $guard,
        private readonly AdnGuard $adnGuard,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        Gate::authorize('viewAny', Nfe::class);

        // Janela de 30 dias para as métricas do painel.
        $desde = now()->subDays(30);

        return response()->json([
            'nfes' => [
                'total' => Nfe::count(),
                'ultimos_30_dias' => Nfe::where('data_emissao', '>=', $desde)->count(),
                'valor_ultimos_30_dias' => (float) Nfe::where('data_emissao', '>=', $desde)->sum('valor_total'),
                'resumos' => Nfe::where('estado', Nfe::ESTADO_RESUMO)->count(),
            ],
            // `null` com a recepção de NFS-e desligada: a SPA esconde o bloco.
            'nfses' => $this->nfseHabilitada() ? $this->resumoNfse($desde) : null,
            'unidades' => [
                'total' => Unidade::count(),
                'ativas' => Unidade::where('ativo', true)->count(),
            ],
            'certificados' => [
                'vencendo_30_dias' => Certificate::where('ativo', true)
                    ->whereNotNull('valido_ate')
                    ->whereBetween('valido_ate', [now(), now()->addDays(30)])
                    ->count(),
                'vencidos' => Certificate::where('ativo', true)
                    ->whereNotNull('valido_ate')
                    ->where('valido_ate', '<', now())
                    ->count(),
                // Explicítamente marcados pelo comando `certificate-expiry:mark`.
                // Filtra `ativo` como `vencidos`: um certificado marcado e
                // depois ROTACIONADO (ativo=false, expirado_em preservado) não
                // é mais o corrente e não deve inflar o painel.
                'expirados' => Certificate::where('ativo', true)
                    ->whereNotNull('expirado_em')
                    ->count(),
            ],
            'sync' => [
                'ultimas' => SyncRun::latest('iniciado_em')->limit(5)->get()->map(fn (SyncRun $r) => [
                    'id' => $r->id,
                    'unidade_id' => $r->unidade_id,
                    'tipo' => $r->tipo ?? SyncRun::TIPO_NFE,
                    'status' => $r->status,
                    'docs_count' => $r->docs_count,
                    'iniciado_em' => $r->iniciado_em?->toIso8601String(),
                ]),
                'com_erro_24h' => SyncRun::where('status', SyncRun::STATUS_ERRO)
                    ->where('iniciado_em', '>=', now()->subDay())
                    ->count(),
                'circuito_aberto' => $this->guard->isOpen(),
                'circuito_adn_aberto' => $this->nfseHabilitada() && $this->adnGuard->isOpen(),
            ],
            'graficos' => [
                'por_dia' => $this->porDia(),
                'fornecedores' => $this->maioresFornecedores(),
                'nfse_por_dia' => $this->nfseHabilitada() ? $this->nfsePorDia() : null,
                'nfse_prestadores' => $this->nfseHabilitada() ? $this->maioresPrestadores() : null,
            ],
        ]);
    }

    private function nfseHabilitada(): bool
    {
        return (bool) config('nfegov.adn.habilitado', true);
    }

    /**
     * Notas de serviço RECEBIDAS (tomador) da entidade ativa.
     *
     * "Recebidas nos 30 dias" e o valor deixam de fora as canceladas e as
     * substituídas (não são mais serviço a pagar); as canceladas viram um
     * número à parte. "Sem manifestação" são as recebidas normais que o
     * tomador ainda não confirmou nem rejeitou.
     *
     * @return array<string, int|float>
     */
    private function resumoNfse(\DateTimeInterface $desde): array
    {
        $recebidas = fn () => Nfse::where('papel', Nfse::PAPEL_TOMADOR);
        $validas = fn () => $recebidas()->where('situacao', Nfse::SITUACAO_NORMAL);

        return [
            'total' => $recebidas()->count(),
            'ultimos_30_dias' => $validas()->where('data_emissao', '>=', $desde)->count(),
            'valor_ultimos_30_dias' => (float) $validas()->where('data_emissao', '>=', $desde)->sum('valor_servico'),
            'canceladas_30_dias' => $recebidas()
                ->whereIn('situacao', [Nfse::SITUACAO_CANCELADA, Nfse::SITUACAO_SUBSTITUIDA])
                ->where('data_emissao', '>=', $desde)
                ->count(),
            'sem_manifestacao' => $validas()->whereNull('manifestacao')->count(),
        ];
    }

    /**
     * NFS-e recebidas por dia de emissão nos últimos 30 dias (hoje incluso),
     * sempre com os 30 dias (zero nos dias sem nota).
     *
     * @return list<array{data: string, quantidade: int, valor: float}>
     */
    private function nfsePorDia(): array
    {
        $inicio = now()->startOfDay()->subDays(29);

        $linhas = Nfse::query()
            ->where('papel', Nfse::PAPEL_TOMADOR)
            ->where('situacao', Nfse::SITUACAO_NORMAL)
            ->where('data_emissao', '>=', $inicio)
            ->selectRaw('DATE(data_emissao) as dia, COUNT(*) as quantidade, COALESCE(SUM(valor_servico), 0) as valor')
            ->groupByRaw('DATE(data_emissao)')
            ->get()
            ->keyBy(fn ($l) => substr((string) $l->dia, 0, 10));

        $dias = [];
        for ($i = 0; $i < 30; $i++) {
            $data = $inicio->copy()->addDays($i)->toDateString();
            $linha = $linhas->get($data);
            $dias[] = [
                'data' => $data,
                'quantidade' => (int) ($linha->quantidade ?? 0),
                'valor' => (float) ($linha->valor ?? 0),
            ];
        }

        return $dias;
    }

    /**
     * Os 5 prestadores com maior valor em NFS-e recebidas nos últimos 30 dias.
     *
     * @return list<array{documento: string|null, nome: string, quantidade: int, valor: float}>
     */
    private function maioresPrestadores(): array
    {
        return Nfse::query()
            ->where('papel', Nfse::PAPEL_TOMADOR)
            ->where('situacao', Nfse::SITUACAO_NORMAL)
            ->where('data_emissao', '>=', now()->subDays(30))
            ->whereNotNull('prestador_documento')
            ->selectRaw('prestador_documento, MAX(prestador_nome) as nome, COUNT(*) as quantidade, COALESCE(SUM(valor_servico), 0) as valor')
            ->groupBy('prestador_documento')
            ->orderByDesc('valor')
            ->limit(5)
            ->get()
            ->map(fn ($l) => [
                'documento' => $l->prestador_documento,
                'nome' => $l->nome ?: $l->prestador_documento,
                'quantidade' => (int) $l->quantidade,
                'valor' => (float) $l->valor,
            ])
            ->all();
    }

    /**
     * NF-e por dia de emissão nos últimos 30 dias (hoje incluso).
     *
     * Devolve sempre os 30 dias, com zero nos dias sem nota, para o gráfico
     * não "pular" datas.
     *
     * @return list<array{data: string, quantidade: int, valor: float}>
     */
    private function porDia(): array
    {
        $inicio = now()->startOfDay()->subDays(29);

        $linhas = Nfe::query()
            ->where('data_emissao', '>=', $inicio)
            ->selectRaw('DATE(data_emissao) as dia, COUNT(*) as quantidade, COALESCE(SUM(valor_total), 0) as valor')
            ->groupByRaw('DATE(data_emissao)')
            ->get()
            ->keyBy(fn ($l) => substr((string) $l->dia, 0, 10));

        $dias = [];
        for ($i = 0; $i < 30; $i++) {
            $data = $inicio->copy()->addDays($i)->toDateString();
            $linha = $linhas->get($data);
            $dias[] = [
                'data' => $data,
                'quantidade' => (int) ($linha->quantidade ?? 0),
                'valor' => (float) ($linha->valor ?? 0),
            ];
        }

        return $dias;
    }

    /**
     * Os 5 fornecedores (emitentes) com maior valor em NF-e nos últimos 30 dias.
     *
     * @return list<array{cnpj: string|null, nome: string, quantidade: int, valor: float}>
     */
    private function maioresFornecedores(): array
    {
        return Nfe::query()
            ->where('data_emissao', '>=', now()->subDays(30))
            ->whereNotNull('emitente_cnpj')
            ->selectRaw('emitente_cnpj, MAX(emitente_nome) as nome, COUNT(*) as quantidade, COALESCE(SUM(valor_total), 0) as valor')
            ->groupBy('emitente_cnpj')
            ->orderByDesc('valor')
            ->limit(5)
            ->get()
            ->map(fn ($l) => [
                'cnpj' => $l->emitente_cnpj,
                'nome' => $l->nome ?: $l->emitente_cnpj,
                'quantidade' => (int) $l->quantidade,
                'valor' => (float) $l->valor,
            ])
            ->all();
    }
}
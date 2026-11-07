<?php

namespace App\Http\Controllers\Api;

use App\Domain\Sefaz\SefazGuard;
use App\Http\Controllers\Controller;
use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\SyncRun;
use App\Models\Unidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Painel: totais, certificados vencendo e últimas sincronizações.
 *
 * Escopado ao tenant ativo. Também expõe o estado do circuit breaker da SEFAZ
 * (freio global), para que o operador saiba que o consumo está suspenso.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly SefazGuard $guard)
    {
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
                    'status' => $r->status,
                    'docs_count' => $r->docs_count,
                    'iniciado_em' => $r->iniciado_em?->toIso8601String(),
                ]),
                'com_erro_24h' => SyncRun::where('status', SyncRun::STATUS_ERRO)
                    ->where('iniciado_em', '>=', now()->subDay())
                    ->count(),
                'circuito_aberto' => $this->guard->isOpen(),
            ],
            'graficos' => [
                'por_dia' => $this->porDia(),
                'fornecedores' => $this->maioresFornecedores(),
            ],
        ]);
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
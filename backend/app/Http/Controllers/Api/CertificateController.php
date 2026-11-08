<?php

namespace App\Http\Controllers\Api;

use App\Domain\Certificate\CertificateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCertificateRequest;
use App\Models\Certificate;
use App\Models\Concerns\Auditable;
use App\Models\Unidade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Certificado e-CNPJ por unidade.
 *
 * - `GET  /api/unidades/{unidade}/certificado` — apenas METADADOS.
 * - `POST /api/unidades/{unidade}/certificado` — upload/rotação (step-up).
 *   Admin envia a qualquer momento; operador só troca um certificado vencido.
 *
 * NENHUMA resposta inclui a senha ou o conteúdo do PFX.
 *
 * Nota de isolamento: a unidade é resolvida AQUI (via query escopada por
 * tenant), não por route model binding. O binding rodaria antes do middleware
 * `tenant`, encontrando a unidade sem contexto; resolver manualmente garante
 * que uma unidade de outro tenant resulte em 404 (jamais revelamos existência).
 */
class CertificateController extends Controller
{
    use Auditable;

    public function __construct(private readonly CertificateService $service)
    {
    }

    public function show(Request $request, int $unidade): JsonResponse
    {
        $unidade = $this->resolverUnidade($unidade);

        Gate::authorize('viewCertificate', $unidade);

        $cert = $this->service->activeFor($unidade);

        return response()->json([
            'certificado' => $cert ? $this->metadados($cert) : null,
            // A SPA mostra o botão de envio só para quem pode usá-lo (ex.: o
            // operador só troca o certificado depois que ele vence).
            'pode_atualizar' => Gate::allows('atualizarCertificado', $unidade),
        ]);
    }

    public function store(StoreCertificateRequest $request, int $unidade): JsonResponse
    {
        $unidade = $this->resolverUnidade($unidade);

        if (Gate::denies('atualizarCertificado', $unidade)) {
            abort(403, $request->user()->can('certificado.renovar')
                ? 'Seu perfil só pode atualizar o certificado quando ele estiver vencido.'
                : 'Você não tem permissão para alterar o certificado desta unidade.');
        }

        $cert = $this->service->store(
            $unidade,
            $request->file('arquivo'),
            $request->input('senha'),
        );

        // Trilha de auditoria: registra a rotação SEM dados sensíveis.
        $this->audit(
            'certificado.rotacionado',
            ['entidade' => 'unidade', 'cnpj' => $cert->cnpj, 'valido_ate' => $cert->valido_ate?->toIso8601String()],
            'unidade',
            $unidade->id,
            $request->user(),
            $request,
        );

        return response()->json(['certificado' => $this->metadados($cert)], 201);
    }

    /**
     * Busca a unidade respeitando o tenant ativo (global scope). 404 se não
     * pertencer ao tenant do usuário.
     */
    private function resolverUnidade(int $id): Unidade
    {
        return Unidade::findOrFail($id);
    }

    /**
     * Representação pública: só metadados; nunca senha nem caminho do PFX.
     */
    private function metadados(Certificate $cert): array
    {
        return [
            'id' => $cert->id,
            'unidade_id' => $cert->unidade_id,
            'cnpj' => $cert->cnpj,
            'titular' => $cert->titular,
            'emissor' => $cert->emissor,
            'valido_de' => $cert->valido_de,
            'valido_ate' => $cert->valido_ate,
            'dias_restantes' => $cert->diasRestantes(),
            'vencido' => $cert->vencido(),
            'expirado_em' => $cert->expirado_em,
            'foi_expirado' => $cert->foiExpirado(),
            'ativo' => $cert->ativo,
        ];
    }
}
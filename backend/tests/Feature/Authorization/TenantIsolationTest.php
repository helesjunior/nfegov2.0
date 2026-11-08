<?php

namespace Tests\Feature\Authorization;

use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Isolamento entre tenants (Review Focus #4).
 *
 * Duas camadas de defesa:
 * 1. `BelongsToTenant` (global scope) filtra as consultas por tenant.
 * 2. Policies checam o vínculo N:N (`tenant_user`) do usuário com o tenant do
 *    recurso — não a coluna legada `users.tenant_id`.
 *
 * Um tenant NUNCA deve enxergar dados de outro. Quando o recurso não é do
 * tenant do usuário, a resposta é 404 (não 403): não revelamos existência.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        // Rotas de teste que exercitam o mesmo caminho das rotas reais:
        // auth + policy + consulta escopada por tenant.
        Route::middleware(['web', 'auth:sanctum'])->prefix('api/_test')->group(function () {
            Route::get('/nfes/{chave}', function (string $chave) {
                $nfe = Nfe::where('chave', $chave)->firstOrFail();
                abort_unless(request()->user()->can('view', $nfe), 404);

                return response()->json(['id' => $nfe->id, 'chave' => $nfe->chave]);
            });

            Route::get('/unidades/{id}', function (int $id) {
                $unidade = Unidade::findOrFail($id);
                abort_unless(request()->user()->can('view', $unidade), 404);

                return response()->json(['id' => $unidade->id]);
            });
        });
    }

    private function usuarioDe(Tenant $tenant, string $papel = 'admin-tenant'): User
    {
        // `comTenant` cria o vínculo em `tenant_user` (fonte de verdade) e
        // preenche `users.tenant_id` por compatibilidade — a autorização passa a
        // depender do vínculo, não da coluna legada.
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        return $u;
    }

    public function test_usuario_nao_acessa_nfe_de_outro_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $u = $this->usuarioDe($a);
        $nfe = Nfe::factory()->create([
            'tenant_id' => $b->id,
            'unidade_id' => Unidade::factory()->create(['tenant_id' => $b->id])->id,
        ]);

        // 404: não revelamos que a NF-e existe.
        $this->actingAs($u)->getJson("/api/_test/nfes/{$nfe->chave}")->assertNotFound();
    }

    public function test_usuario_acessa_nfe_do_proprio_tenant(): void
    {
        $a = Tenant::factory()->create();
        $u = $this->usuarioDe($a);
        $nfe = Nfe::factory()->create([
            'tenant_id' => $a->id,
            'unidade_id' => Unidade::factory()->create(['tenant_id' => $a->id])->id,
        ]);

        $this->actingAs($u)->getJson("/api/_test/nfes/{$nfe->chave}")
            ->assertOk()
            ->assertJsonPath('id', $nfe->id);
    }

    public function test_usuario_nao_acessa_unidade_de_outro_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $u = $this->usuarioDe($a);
        $unidade = Unidade::factory()->create(['tenant_id' => $b->id]);

        $this->actingAs($u)->getJson("/api/_test/unidades/{$unidade->id}")->assertNotFound();
    }

    public function test_super_admin_acessa_qualquer_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        // super-admin tem tenant_id nulo.
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $nfe = Nfe::factory()->create([
            'tenant_id' => $b->id,
            'unidade_id' => Unidade::factory()->create(['tenant_id' => $b->id])->id,
        ]);

        $this->actingAs($super)->getJson("/api/_test/nfes/{$nfe->chave}")->assertOk();
    }

    public function test_policies_negam_certificado_de_outro_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $u = $this->usuarioDe($a);

        // Certificado do tenant B (usa semGlobalScopes para pegar explicitamente).
        $unidadeB = Unidade::factory()->create(['tenant_id' => $b->id]);
        $cert = Certificate::factory()->create([
            'tenant_id' => $b->id,
            'unidade_id' => $unidadeB->id,
        ]);

        $this->assertFalse($u->can('view', $cert));
        $this->assertFalse($u->can('rotate', $cert));
    }

    public function test_consulta_pode_ver_mas_nao_gerenciar(): void
    {
        $a = Tenant::factory()->create();
        $u = $this->usuarioDe($a, 'consulta');
        $unidade = Unidade::factory()->create(['tenant_id' => $a->id]);

        $this->assertTrue($u->can('view', $unidade));
        $this->assertFalse($u->can('update', $unidade));
        $this->assertFalse($u->can('create', \App\Models\Unidade::class));
    }

    public function test_operador_ve_mas_nao_gerencia_certificado_em_dia(): void
    {
        $a = Tenant::factory()->create();
        $u = $this->usuarioDe($a, 'operador');
        $unidade = Unidade::factory()->create(['tenant_id' => $a->id]);

        $cert = Certificate::factory()->create([
            'tenant_id' => $a->id,
            'unidade_id' => $unidade->id,
        ]);

        $this->assertFalse($u->can('create', \App\Models\Certificate::class));
        $this->assertFalse($u->can('rotate', $cert));
        // Operador vê os metadados, mas só troca o certificado quando vencer.
        $this->assertTrue($u->can('viewCertificate', $unidade));
        $this->assertFalse($u->can('atualizarCertificado', $unidade));
    }

    public function test_admin_tenant_gerencia_certificado_do_proprio_tenant(): void
    {
        $a = Tenant::factory()->create();
        $u = $this->usuarioDe($a, 'admin-tenant');
        $unidade = Unidade::factory()->create(['tenant_id' => $a->id]);
        $cert = Certificate::factory()->create([
            'tenant_id' => $a->id,
            'unidade_id' => $unidade->id,
        ]);

        $this->assertTrue($u->can('create', \App\Models\Certificate::class));
        $this->assertTrue($u->can('rotate', $cert));
        $this->assertTrue($u->can('viewCertificate', $unidade));
    }
}
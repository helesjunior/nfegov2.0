<?php

namespace Tests\Feature\Authorization;

use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regras dos perfis, ponta a ponta pela API:
 *
 * - admin-tenant: unidades (CRUD) e certificado a qualquer momento; sem Entidades.
 * - operador: vê Unidades, não inclui/edita/desativa; troca o certificado só
 *   quando ele está vencido; sem Usuários e Entidades.
 * - consulta: exporta/baixa, mas não manifesta NFS-e; sem Unidades (gestão),
 *   Usuários e Entidades.
 */
class PerfisPermissoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesSeeder::class);
    }

    private function usuario(Tenant $tenant, string $papel): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    private function unidade(Tenant $tenant): Unidade
    {
        return Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181', // mesmo CNPJ da fixture
        ]);
    }

    private function pfx(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/cert_e_cnpj.pfx'),
            'certificado.pfx',
            'application/x-pkcs12',
            null,
            true,
        );
    }

    private function enviarCertificado(User $u, Unidade $unidade)
    {
        return $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(),
            'senha' => 'senha-pfx',
        ]);
    }

    public function test_operador_nao_inclui_edita_nem_desativa_unidade(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidade($tenant);
        $operador = $this->usuario($tenant, 'operador');

        $this->actingAs($operador)->getJson('/api/unidades')->assertOk();

        $this->actingAs($operador)->postJson('/api/unidades', [
            'nome' => 'Nova', 'cnpj' => '99888777000166', 'uf' => 'DF', 'tp_amb' => 2,
        ])->assertForbidden();

        $this->actingAs($operador)->putJson("/api/unidades/{$unidade->id}", [
            'nome' => 'Renomeada', 'cnpj' => $unidade->cnpj, 'uf' => 'DF', 'tp_amb' => 2,
        ])->assertForbidden();

        $this->actingAs($operador)->putJson("/api/unidades/{$unidade->id}/ativo", ['ativo' => false])
            ->assertForbidden();

        $this->assertTrue($unidade->fresh()->ativo);
    }

    public function test_operador_ve_o_certificado_mas_nao_troca_um_certificado_em_dia(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidade($tenant);
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'ativo' => true,
        ]);
        $operador = $this->usuario($tenant, 'operador');

        $this->actingAs($operador)->getJson("/api/unidades/{$unidade->id}/certificado")
            ->assertOk()
            ->assertJsonPath('pode_atualizar', false);

        $this->enviarCertificado($operador, $unidade)
            ->assertForbidden()
            ->assertJsonPath('message', 'Seu perfil só pode atualizar o certificado quando ele estiver vencido.');
    }

    public function test_operador_atualiza_certificado_vencido(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidade($tenant);
        $antigo = Certificate::factory()->vencido()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'ativo' => true,
        ]);
        $operador = $this->usuario($tenant, 'operador');

        $this->actingAs($operador)->getJson("/api/unidades/{$unidade->id}/certificado")
            ->assertJsonPath('pode_atualizar', true);

        $this->enviarCertificado($operador, $unidade)->assertCreated();
        $this->assertFalse($antigo->fresh()->ativo);
    }

    public function test_operador_nao_envia_certificado_para_unidade_sem_certificado(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidade($tenant);
        $operador = $this->usuario($tenant, 'operador');

        $this->enviarCertificado($operador, $unidade)->assertForbidden();
    }

    public function test_admin_tenant_troca_certificado_a_qualquer_momento(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidade($tenant);
        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'ativo' => true,
        ]);
        $admin = $this->usuario($tenant, 'admin-tenant');

        $this->actingAs($admin)->getJson("/api/unidades/{$unidade->id}/certificado")
            ->assertJsonPath('pode_atualizar', true);

        $this->enviarCertificado($admin, $unidade)->assertCreated();
    }

    public function test_consulta_nao_ve_o_certificado_nem_gerencia_unidades(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = $this->unidade($tenant);
        $consulta = $this->usuario($tenant, 'consulta');

        // A listagem segue liberada: alimenta os filtros de NF-e/NFS-e.
        $this->actingAs($consulta)->getJson('/api/unidades')->assertOk();

        $this->actingAs($consulta)->getJson("/api/unidades/{$unidade->id}/certificado")->assertForbidden();
        $this->enviarCertificado($consulta, $unidade)->assertForbidden();
        $this->actingAs($consulta)->postJson('/api/unidades', [
            'nome' => 'Nova', 'cnpj' => '99888777000166', 'uf' => 'DF', 'tp_amb' => 2,
        ])->assertForbidden();
    }

    public function test_operador_e_consulta_nao_acessam_usuarios_nem_entidades(): void
    {
        $tenant = Tenant::factory()->create();

        foreach (['operador', 'consulta'] as $papel) {
            $u = $this->usuario($tenant, $papel);

            $this->actingAs($u)->getJson('/api/usuarios')->assertForbidden();
            $this->actingAs($u)->getJson('/api/tenants')->assertForbidden();
        }
    }

    public function test_admin_tenant_acessa_usuarios_mas_nao_entidades(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->usuario($tenant, 'admin-tenant');

        $this->actingAs($admin)->getJson('/api/usuarios')->assertOk();
        $this->actingAs($admin)->getJson('/api/tenants')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/tenants', ['nome' => 'X', 'cnpj' => '99888777000166'])
            ->assertForbidden();
    }

    public function test_consulta_exporta_notas(): void
    {
        $tenant = Tenant::factory()->create();
        $consulta = $this->usuario($tenant, 'consulta');
        Queue::fake();

        $this->actingAs($consulta)->postJson('/api/nfes/export', ['formato' => 'csv'])->assertStatus(202);
        $this->actingAs($consulta)->postJson('/api/nfses/export', ['formato' => 'csv'])->assertStatus(202);
    }

    public function test_usuario_vinculado_so_pela_tabela_nn_nao_alcanca_outra_entidade(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $unidadeB = $this->unidade($b);

        // Vínculo só no N:N (sem a coluna legada `tenant_id`).
        $u = User::factory()->create(['tenant_id' => null]);
        $u->tenants()->attach($a->id);
        $u->assignRole('admin-tenant');

        $this->assertFalse($u->can('update', $unidadeB));
        $this->assertFalse($u->can('atualizarCertificado', $unidadeB));
    }
}

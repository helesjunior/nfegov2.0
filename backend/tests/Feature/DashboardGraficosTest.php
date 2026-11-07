<?php

namespace Tests\Feature;

use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Painel: dados dos gráficos (NF-e por dia e maiores fornecedores).
 *
 * Sempre escopados ao tenant ativo; notas de outra entidade nunca aparecem.
 */
class DashboardGraficosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function admin(Tenant $tenant): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    private function nfe(Tenant $tenant, Unidade $unidade, array $attrs): Nfe
    {
        return Nfe::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
        ], $attrs));
    }

    public function test_por_dia_traz_30_dias_com_zeros_e_soma_por_data(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $tenant = Tenant::factory()->create();
        $u = $this->admin($tenant);
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->nfe($tenant, $unidade, ['data_emissao' => now()->setTime(9, 0), 'valor_total' => 100]);
        $this->nfe($tenant, $unidade, ['data_emissao' => now()->setTime(10, 0), 'valor_total' => 50.5]);
        $this->nfe($tenant, $unidade, ['data_emissao' => now()->subDays(29)->setTime(8, 0), 'valor_total' => 10]);
        // Fora da janela: não entra.
        $this->nfe($tenant, $unidade, ['data_emissao' => now()->subDays(40), 'valor_total' => 999]);

        $dias = $this->actingAs($u)->getJson('/api/dashboard')->assertOk()->json('graficos.por_dia');

        $this->assertCount(30, $dias);
        $this->assertSame(now()->subDays(29)->toDateString(), $dias[0]['data']);
        $this->assertSame(1, $dias[0]['quantidade']);
        $this->assertSame(now()->toDateString(), $dias[29]['data']);
        $this->assertSame(2, $dias[29]['quantidade']);
        $this->assertEquals(150.5, $dias[29]['valor']);
        $this->assertSame(0, $dias[15]['quantidade']);
        $this->assertSame(3, array_sum(array_column($dias, 'quantidade')));
    }

    public function test_maiores_fornecedores_ordena_por_valor_e_limita_a_cinco(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->admin($tenant);
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        foreach (range(1, 6) as $i) {
            $this->nfe($tenant, $unidade, [
                'emitente_cnpj' => str_pad((string) $i, 14, '0', STR_PAD_LEFT),
                'emitente_nome' => "Fornecedor {$i}",
                'valor_total' => $i * 100,
                'data_emissao' => now()->subDay(),
            ]);
        }
        // Segunda nota do fornecedor 1: soma 100 + 1000 = 1100, vira o maior.
        $this->nfe($tenant, $unidade, [
            'emitente_cnpj' => str_pad('1', 14, '0', STR_PAD_LEFT),
            'emitente_nome' => 'Fornecedor 1',
            'valor_total' => 1000,
            'data_emissao' => now()->subDay(),
        ]);

        $forn = $this->actingAs($u)->getJson('/api/dashboard')->assertOk()->json('graficos.fornecedores');

        $this->assertCount(5, $forn);
        $this->assertSame('Fornecedor 1', $forn[0]['nome']);
        $this->assertSame(2, $forn[0]['quantidade']);
        $this->assertEquals(1100, $forn[0]['valor']);
        $this->assertSame('Fornecedor 6', $forn[1]['nome']);
        $this->assertNotContains('Fornecedor 2', array_column($forn, 'nome'));
    }

    public function test_graficos_ignoram_notas_de_outra_entidade(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = $this->admin($a);
        $unidadeB = Unidade::factory()->create(['tenant_id' => $b->id]);

        $this->nfe($b, $unidadeB, ['data_emissao' => now(), 'valor_total' => 500]);

        $resp = $this->actingAs($u)->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0, array_sum(array_column($resp->json('graficos.por_dia'), 'quantidade')));
        $this->assertSame([], $resp->json('graficos.fornecedores'));
    }
}

<?php

namespace Tests\Feature;

use App\Domain\Nfse\AdnGuard;
use App\Models\Nfse;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Painel: indicadores e gráficos de NFS-e.
 *
 * Contam só as notas RECEBIDAS (a unidade é a tomadora); canceladas e
 * substituídas saem dos totais e dos gráficos. Sempre escopados à entidade ativa.
 */
class DashboardNfseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuario(Tenant $tenant, string $papel = 'operador'): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        return $u;
    }

    private function nota(Unidade $unidade, array $attrs = []): Nfse
    {
        return Nfse::factory()->create(array_merge([
            'unidade_id' => $unidade->id,
            'papel' => Nfse::PAPEL_TOMADOR,
            'situacao' => Nfse::SITUACAO_NORMAL,
            'data_emissao' => now()->subDays(2),
            'valor_servico' => 100,
        ], $attrs));
    }

    public function test_indicadores_contam_so_recebidas_e_separam_canceladas(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->nota($unidade, ['valor_servico' => 100]);
        $this->nota($unidade, ['valor_servico' => 50.5, 'manifestacao' => Nfse::MANIFESTACAO_CONFIRMADA]);
        // Fora da janela de 30 dias: conta no total, não nos 30 dias.
        $this->nota($unidade, ['data_emissao' => now()->subDays(60), 'valor_servico' => 999]);
        // Cancelada e substituída: totais à parte, sem valor.
        $this->nota($unidade, ['situacao' => Nfse::SITUACAO_CANCELADA, 'valor_servico' => 700]);
        $this->nota($unidade, ['situacao' => Nfse::SITUACAO_SUBSTITUIDA, 'valor_servico' => 300]);
        // Emitida pela própria unidade: não é "recebida".
        $this->nota($unidade, ['papel' => Nfse::PAPEL_PRESTADOR, 'valor_servico' => 5000]);

        $resp = $this->actingAs($this->usuario($tenant))->getJson('/api/dashboard')->assertOk();

        $this->assertSame(5, $resp->json('nfses.total'));
        $this->assertSame(2, $resp->json('nfses.ultimos_30_dias'));
        $this->assertEquals(150.5, $resp->json('nfses.valor_ultimos_30_dias'));
        $this->assertSame(2, $resp->json('nfses.canceladas_30_dias'));
        // Normais e recebidas sem manifestação: a de 100 e a de 999 (antiga).
        $this->assertSame(2, $resp->json('nfses.sem_manifestacao'));
    }

    public function test_nfse_por_dia_traz_30_dias_e_soma_por_data(): void
    {
        $this->travelTo(now()->setTime(12, 0));
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->nota($unidade, ['data_emissao' => now()->setTime(9, 0), 'valor_servico' => 100]);
        $this->nota($unidade, ['data_emissao' => now()->setTime(10, 0), 'valor_servico' => 50.5]);
        $this->nota($unidade, ['data_emissao' => now()->subDays(29)->setTime(8, 0), 'valor_servico' => 10]);
        $this->nota($unidade, ['data_emissao' => now()->subDays(40), 'valor_servico' => 999]);
        $this->nota($unidade, ['data_emissao' => now(), 'situacao' => Nfse::SITUACAO_CANCELADA, 'valor_servico' => 700]);

        $dias = $this->actingAs($this->usuario($tenant))->getJson('/api/dashboard')->assertOk()->json('graficos.nfse_por_dia');

        $this->assertCount(30, $dias);
        $this->assertSame(now()->subDays(29)->toDateString(), $dias[0]['data']);
        $this->assertSame(1, $dias[0]['quantidade']);
        $this->assertSame(2, $dias[29]['quantidade']);
        $this->assertEquals(150.5, $dias[29]['valor']);
        $this->assertSame(3, array_sum(array_column($dias, 'quantidade')));
    }

    public function test_maiores_prestadores_ordena_por_valor_e_limita_a_cinco(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        foreach (range(1, 6) as $i) {
            $this->nota($unidade, [
                'prestador_documento' => str_pad((string) $i, 14, '0', STR_PAD_LEFT),
                'prestador_nome' => "Prestador {$i}",
                'valor_servico' => $i * 100,
            ]);
        }
        $this->nota($unidade, [
            'prestador_documento' => str_pad('1', 14, '0', STR_PAD_LEFT),
            'prestador_nome' => 'Prestador 1',
            'valor_servico' => 1000,
        ]);

        $lista = $this->actingAs($this->usuario($tenant))->getJson('/api/dashboard')->json('graficos.nfse_prestadores');

        $this->assertCount(5, $lista);
        $this->assertSame('Prestador 1', $lista[0]['nome']);
        $this->assertSame(2, $lista[0]['quantidade']);
        $this->assertEquals(1100, $lista[0]['valor']);
        $this->assertSame('Prestador 6', $lista[1]['nome']);
        $this->assertNotContains('Prestador 2', array_column($lista, 'nome'));
    }

    public function test_ignora_nfse_de_outra_entidade(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $unidadeB = Unidade::factory()->create(['tenant_id' => $b->id]);
        $this->nota($unidadeB, ['valor_servico' => 500]);

        $resp = $this->actingAs($this->usuario($a))->getJson('/api/dashboard')->assertOk();

        $this->assertSame(0, $resp->json('nfses.total'));
        $this->assertSame(0, array_sum(array_column($resp->json('graficos.nfse_por_dia'), 'quantidade')));
        $this->assertSame([], $resp->json('graficos.nfse_prestadores'));
    }

    public function test_com_a_recepcao_desligada_os_blocos_de_nfse_somem(): void
    {
        config(['nfegov.adn.habilitado' => false]);
        $tenant = Tenant::factory()->create();

        $resp = $this->actingAs($this->usuario($tenant))->getJson('/api/dashboard')->assertOk();

        $this->assertNull($resp->json('nfses'));
        $this->assertNull($resp->json('graficos.nfse_por_dia'));
        $this->assertNull($resp->json('graficos.nfse_prestadores'));
        $this->assertFalse($resp->json('sync.circuito_adn_aberto'));
        // A NF-e continua igual.
        $this->assertNotNull($resp->json('nfes'));
        $this->assertCount(30, $resp->json('graficos.por_dia'));
    }

    public function test_ultimas_sincronizacoes_trazem_o_tipo_e_o_freio_do_adn(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        SyncRun::create(['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id, 'tipo' => 'nfe', 'status' => 'sucesso', 'iniciado_em' => now()->subMinute()]);
        SyncRun::create(['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id, 'tipo' => 'nfse', 'status' => 'sucesso', 'iniciado_em' => now()]);
        $user = $this->actingAs($this->usuario($tenant));

        $resp = $user->getJson('/api/dashboard')->assertOk();
        $this->assertSame(['nfse', 'nfe'], array_column($resp->json('sync.ultimas'), 'tipo'));
        $this->assertFalse($resp->json('sync.circuito_adn_aberto'));

        app(AdnGuard::class)->tripCircuitBreaker($unidade);

        $resp = $user->getJson('/api/dashboard')->assertOk();
        $this->assertTrue($resp->json('sync.circuito_adn_aberto'));
        $this->assertFalse($resp->json('sync.circuito_aberto'), 'O freio da SEFAZ é independente.');
    }

    public function test_exige_a_permissao_de_ver_notas(): void
    {
        $tenant = Tenant::factory()->create();
        $semPermissao = User::factory()->comTenant($tenant)->create();

        $this->actingAs($semPermissao)->getJson('/api/dashboard')->assertForbidden();
    }
}

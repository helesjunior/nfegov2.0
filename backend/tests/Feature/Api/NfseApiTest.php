<?php

namespace Tests\Feature\Api;

use App\Jobs\ExportNfseJob;
use App\Models\Nfse;
use App\Models\NfseEvento;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * API de NFS-e: escopo do tenant, filtros, detalhe com eventos, XML por URL
 * assinada e exportação. Mesmas permissões da NF-e.
 */
class NfseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function usuario(Tenant $tenant, string $papel = 'operador'): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        return $u;
    }

    public function test_lista_somente_nfses_do_proprio_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = Unidade::factory()->create(['tenant_id' => $a->id]);
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);
        Nfse::factory()->count(3)->create(['unidade_id' => $ua->id]);
        Nfse::factory()->count(2)->create(['unidade_id' => $ub->id]);

        $resp = $this->actingAs($this->usuario($a))->getJson('/api/nfses');

        $resp->assertOk();
        $this->assertSame(3, $resp->json('meta.total'));
    }

    public function test_filtra_por_unidade_papel_e_situacao(): void
    {
        $tenant = Tenant::factory()->create();
        $u1 = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $u2 = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        Nfse::factory()->create(['unidade_id' => $u1->id, 'papel' => 'tomador']);
        Nfse::factory()->create(['unidade_id' => $u1->id, 'papel' => 'prestador']);
        Nfse::factory()->create(['unidade_id' => $u1->id, 'papel' => 'tomador', 'situacao' => 'cancelada']);
        Nfse::factory()->create(['unidade_id' => $u2->id, 'papel' => 'tomador']);

        $user = $this->actingAs($this->usuario($tenant));

        $this->assertSame(3, $user->getJson("/api/nfses?unidade_id={$u1->id}")->json('meta.total'));
        $this->assertSame(2, $user->getJson("/api/nfses?unidade_id={$u1->id}&papel=tomador")->json('meta.total'));
        $this->assertSame(1, $user->getJson("/api/nfses?unidade_id={$u1->id}&papel=tomador&situacao=cancelada")->json('meta.total'));
    }

    public function test_filtra_por_competencia(): void
    {
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        Nfse::factory()->create(['unidade_id' => $u->id, 'competencia' => '2024-01-10', 'data_emissao' => '2024-02-01']);
        Nfse::factory()->create(['unidade_id' => $u->id, 'competencia' => '2024-02-10', 'data_emissao' => '2024-02-11']);

        $resp = $this->actingAs($this->usuario($tenant))
            ->getJson('/api/nfses?data=competencia&de=2024-01-01&ate=2024-01-31');

        $this->assertSame(1, $resp->json('meta.total'));
    }

    public function test_busca_textual_por_prestador(): void
    {
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        Nfse::factory()->create(['unidade_id' => $u->id, 'prestador_nome' => 'Serralheria Aço Forte']);
        Nfse::factory()->create(['unidade_id' => $u->id, 'prestador_nome' => 'Outra Empresa']);

        $resp = $this->actingAs($this->usuario($tenant))->getJson('/api/nfses?q=serralheria');

        $this->assertSame(1, $resp->json('meta.total'));
    }

    public function test_detalhe_traz_eventos_e_url_assinada_do_xml(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfse = Nfse::factory()->create(['unidade_id' => $u->id, 'xml_path' => 'x/nota.xml', 'situacao' => 'cancelada']);
        Storage::disk('local')->put('x/nota.xml', '<NFSe/>');
        NfseEvento::create([
            'tenant_id' => $tenant->id, 'unidade_id' => $u->id, 'nfse_id' => $nfse->id,
            'chave' => $nfse->chave, 'nsu' => 99, 'tipo_evento' => 'CANCELAMENTO', 'motivo' => 'erro',
        ]);

        $user = $this->usuario($tenant);
        $resp = $this->actingAs($user)->getJson("/api/nfses/{$nfse->chave}?unidade_id={$u->id}");

        $resp->assertOk()
            ->assertJsonPath('data.situacao', 'cancelada')
            ->assertJsonPath('data.eventos.0.tipo_evento', 'CANCELAMENTO');

        $url = $resp->json('data.xml_url');
        $this->assertNotNull($url);

        $this->actingAs($user)->get($url)->assertOk()->assertSee('<NFSe/>', false);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'nfse.xml.baixado']);
    }

    public function test_xml_sem_assinatura_e_recusado(): void
    {
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfse = Nfse::factory()->create(['unidade_id' => $u->id, 'xml_path' => 'x/nota.xml']);

        $this->actingAs($this->usuario($tenant))
            ->get("/api/nfses/{$nfse->chave}/xml")
            ->assertForbidden();
    }

    public function test_nota_de_outro_tenant_e_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);
        $nfse = Nfse::factory()->create(['unidade_id' => $ub->id]);

        $this->actingAs($this->usuario($a))->getJson("/api/nfses/{$nfse->chave}")->assertNotFound();
    }

    public function test_consulta_ve_mas_nao_recebe_url_do_xml(): void
    {
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfse = Nfse::factory()->create(['unidade_id' => $u->id, 'xml_path' => 'x/nota.xml']);

        $this->actingAs($this->usuario($tenant, 'consulta'))
            ->getJson("/api/nfses/{$nfse->chave}")
            ->assertOk()
            ->assertJsonPath('data.xml_url', null);
    }

    public function test_exportacao_agenda_job_com_os_filtros(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->usuario($tenant))
            ->postJson('/api/nfses/export', ['formato' => 'xlsx', 'papel' => 'tomador'])
            ->assertStatus(202);

        Queue::assertPushed(ExportNfseJob::class, fn ($job) => $job->formato === 'xlsx' && $job->filtros['papel'] === 'tomador');
    }

    public function test_exportacao_exige_permissao(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->usuario($tenant, 'consulta'))
            ->postJson('/api/nfses/export', [])
            ->assertForbidden();
    }

    public function test_export_job_gera_csv(): void
    {
        Storage::fake('local');
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        Nfse::factory()->count(2)->create(['unidade_id' => $u->id]);
        $user = $this->usuario($tenant);

        (new ExportNfseJob($tenant->id, $user->id, [], 'csv'))->handle(app(\App\Domain\Nfse\NfseQuery::class));

        $notificacao = $user->notifications()->first();
        $this->assertSame(2, $notificacao->data['total']);
        $this->assertSame('NFS-e', $notificacao->data['documento']);
        Storage::disk('local')->assertExists($notificacao->data['caminho']);
    }

    public function test_sync_runs_filtra_por_tipo(): void
    {
        $tenant = Tenant::factory()->create();
        $u = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        SyncRun::create(['tenant_id' => $tenant->id, 'unidade_id' => $u->id, 'tipo' => 'nfe', 'status' => 'sucesso', 'iniciado_em' => now()]);
        SyncRun::create(['tenant_id' => $tenant->id, 'unidade_id' => $u->id, 'tipo' => 'nfse', 'status' => 'sucesso', 'iniciado_em' => now()]);

        $resp = $this->actingAs($this->usuario($tenant))->getJson('/api/sync-runs?tipo=nfse');

        $this->assertCount(1, $resp->json('data'));
        $this->assertSame('nfse', $resp->json('data.0.tipo'));
    }

    public function test_sem_ordem_segue_o_campo_do_periodo(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id];

        Nfse::factory()->create($base + ['numero' => '1', 'data_emissao' => '2026-10-20 10:00:00', 'competencia' => '2026-01-01']);
        Nfse::factory()->create($base + ['numero' => '2', 'data_emissao' => '2026-09-20 10:00:00', 'competencia' => '2026-08-01']);

        $usuario = $this->usuario($tenant);

        $resp = $this->actingAs($usuario)->getJson('/api/nfses');
        $this->assertSame(['1', '2'], array_column($resp->json('data'), 'numero'));

        $resp = $this->actingAs($usuario)->getJson('/api/nfses?data=competencia&de=2025-01-01');
        $this->assertSame(['2', '1'], array_column($resp->json('data'), 'numero'));
    }

    public function test_ordena_por_prestador_competencia_e_valor(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id];

        Nfse::factory()->create($base + ['numero' => '1', 'prestador_nome' => 'Serralheria', 'competencia' => '2026-03-01', 'valor_servico' => '50.00']);
        Nfse::factory()->create($base + ['numero' => '2', 'prestador_nome' => 'Ábaco Serviços', 'competencia' => '2026-01-01', 'valor_servico' => '700.00']);
        Nfse::factory()->create($base + ['numero' => '3', 'prestador_nome' => 'clínica', 'competencia' => '2026-02-01', 'valor_servico' => '120.00']);

        $usuario = $this->usuario($tenant);
        $numeros = fn (string $qs) => array_column($this->actingAs($usuario)->getJson('/api/nfses?'.$qs)->json('data'), 'numero');

        $this->assertSame(['2', '3', '1'], $numeros('ordem=prestador&direcao=asc'));
        $this->assertSame(['1', '3', '2'], $numeros('ordem=prestador&direcao=desc'));
        $this->assertSame(['2', '3', '1'], $numeros('ordem=competencia&direcao=asc'));
        $this->assertSame(['2', '3', '1'], $numeros('ordem=valor&direcao=desc'));
    }

    public function test_ordem_fora_da_lista_branca_e_recusada(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->usuario($tenant))->getJson('/api/nfses?ordem=id;drop')
            ->assertStatus(422)->assertJsonValidationErrors('ordem');
    }
}

<?php

namespace Tests\Feature\Api;

use App\Jobs\ExportNfeJob;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use App\Notifications\ExportPronto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Exportação assíncrona: CSV/XLSX de metadados e ZIP de XMLs.
 *
 * O trabalho pesado roda em job (streaming): a resposta HTTP é imediata (202)
 * e o usuário é NOTIFICADO quando o arquivo está pronto, por link assinado.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function operador(Tenant $tenant): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole('operador');

        return $u;
    }

    public function test_dispara_export_e_enfileira_job(): void
    {
        Bus::fake();

        $tenant = Tenant::factory()->create();
        $user = $this->operador($tenant);

        $resp = $this->actingAs($user)
            ->postJson('/api/nfes/export', ['formato' => 'csv']);

        $resp->assertStatus(202);
        Bus::assertDispatched(ExportNfeJob::class);
    }

    public function test_export_csv_contem_apenas_nfes_do_tenant(): void
    {
        Storage::fake('local');
        Notification::fake();

        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = Unidade::factory()->create(['tenant_id' => $a->id]);
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);

        Nfe::factory()->count(3)->create(['tenant_id' => $a->id, 'unidade_id' => $ua->id]);
        Nfe::factory()->count(5)->create(['tenant_id' => $b->id, 'unidade_id' => $ub->id]);

        $user = $this->operador($a);

        // Executa o job de forma síncrona para inspecionar o arquivo.
        (new ExportNfeJob($a->id, $user->id, [], 'csv'))->handle(app(\App\Domain\Nfe\NfeQuery::class));

        $arquivo = Storage::disk('local')->files('exports/'.$a->id);
        $this->assertCount(1, $arquivo, 'Deve haver exatamente um arquivo de export.');

        $conteudo = Storage::disk('local')->get($arquivo[0]);
        $linhas = array_filter(explode("\n", trim($conteudo)));

        // Cabeçalho + 3 NF-e do tenant A (nunca as 5 do tenant B).
        $this->assertCount(4, $linhas);
        $this->assertStringContainsString('Chave', $linhas[0] ?? '');
    }

    public function test_export_xlsx_e_gerado(): void
    {
        Storage::fake('local');
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        Nfe::factory()->count(2)->create(['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id]);

        $user = $this->operador($tenant);

        (new ExportNfeJob($tenant->id, $user->id, [], 'xlsx'))->handle(app(\App\Domain\Nfe\NfeQuery::class));

        $arquivo = Storage::disk('local')->files('exports/'.$tenant->id);
        $this->assertCount(1, $arquivo);
        $this->assertStringEndsWith('.xlsx', $arquivo[0]);
    }

    public function test_notifica_quando_export_esta_pronto(): void
    {
        Storage::fake('local');
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $user = $this->operador($tenant);

        (new ExportNfeJob($tenant->id, $user->id, [], 'csv'))->handle(app(\App\Domain\Nfe\NfeQuery::class));

        Notification::assertSentTo(
            $user,
            ExportPronto::class,
            fn (ExportPronto $n) => str_ends_with($n->caminho, '.csv'),
        );
    }

    public function test_export_zip_de_xmls(): void
    {
        Storage::fake('local');
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $user = $this->operador($tenant);

        foreach (range(1, 2) as $i) {
            $chave = str_pad((string) $i, 44, '0', STR_PAD_LEFT);
            $path = 'tenants/'.$tenant->id.'/unidades/'.$unidade->id.'/nfes/2026/10/'.$chave.'.xml';
            Storage::disk('local')->put($path, '<xml>'.$chave.'</xml>');
            Nfe::factory()->create([
                'tenant_id' => $tenant->id,
                'unidade_id' => $unidade->id,
                'chave' => $chave,
                'xml_path' => $path,
            ]);
        }

        (new \App\Jobs\ExportXmlJob($tenant->id, $user->id, []))->handle(app(\App\Domain\Nfe\NfeQuery::class));

        $arquivo = Storage::disk('local')->files('exports/'.$tenant->id);
        $zip = collect($arquivo)->first(fn ($f) => str_ends_with($f, '.zip'));
        $this->assertNotNull($zip, 'O ZIP deve ter sido gerado.');
        $this->assertGreaterThan(0, Storage::disk('local')->size($zip));
    }

    public function test_usuario_sem_permissao_nao_exporta(): void
    {
        $tenant = Tenant::factory()->create();
        $semPapel = User::factory()->comTenant($tenant)->create();

        $this->actingAs($semPapel)
            ->postJson('/api/nfes/export', ['formato' => 'csv'])
            ->assertForbidden();
    }
}
<?php

namespace Tests\Feature\Api;

use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * API REST de NF-e (lista, detalhe, XML e DANFE).
 *
 * Regras centrais:
 * - Toda listagem/leitura é escopada ao tenant do usuário (jamais vaza dado
 *   de outro órgão) — recurso de outro tenant resulta em 404.
 * - O XML é servido por URL ASSINADA e TEMPORÁRIA (download auditável).
 */
class NfeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuarioDoTenant(Tenant $tenant, string $papel = 'operador'): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        return $u;
    }

    public function test_lista_somente_nfes_do_proprio_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = Unidade::factory()->create(['tenant_id' => $a->id]);
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);

        Nfe::factory()->count(3)->create(['tenant_id' => $a->id, 'unidade_id' => $ua->id]);
        Nfe::factory()->count(2)->create(['tenant_id' => $b->id, 'unidade_id' => $ub->id]);

        $resp = $this->actingAs($this->usuarioDoTenant($a))->getJson('/api/nfes');

        $resp->assertOk();
        $this->assertSame(3, $resp->json('meta.total'));
    }

    public function test_filtra_por_periodo(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        Nfe::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'data_emissao' => '2026-10-10 10:00:00',
        ]);
        Nfe::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'data_emissao' => '2026-09-01 10:00:00',
        ]);

        $resp = $this->actingAs($this->usuarioDoTenant($tenant))
            ->getJson('/api/nfes?de=2026-10-01&ate=2026-10-31');

        $resp->assertOk();
        $this->assertSame(1, $resp->json('meta.total'));
    }

    public function test_filtra_por_emitente_e_valor(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        Nfe::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'emitente_cnpj' => '99888777000166', 'valor_total' => 5000.00,
        ]);
        Nfe::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'emitente_cnpj' => '11222333000181', 'valor_total' => 50.00,
        ]);

        $resp = $this->actingAs($this->usuarioDoTenant($tenant))
            ->getJson('/api/nfes?emitente_cnpj=99888777000166&valor_min=1000');

        $resp->assertOk();
        $this->assertSame(1, $resp->json('meta.total'));
        $this->assertSame('99888777000166', $resp->json('data.0.emitente_cnpj'));
    }

    public function test_detalhe_de_nfe_de_outro_tenant_resulta_404(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);
        $nfe = Nfe::factory()->create(['tenant_id' => $b->id, 'unidade_id' => $ub->id]);

        $resp = $this->actingAs($this->usuarioDoTenant($a))->getJson('/api/nfes/'.$nfe->chave);

        $resp->assertNotFound();
    }

    public function test_mostra_detalhe_com_itens(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfe = Nfe::factory()->create(['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id]);

        $resp = $this->actingAs($this->usuarioDoTenant($tenant))->getJson('/api/nfes/'.$nfe->chave);

        $resp->assertOk();
        $this->assertSame($nfe->chave, $resp->json('data.chave'));
    }

    public function test_xml_usa_url_assinada_e_temporaria(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfe = Nfe::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'xml_path' => 'tenants/1/unidades/1/nfes/2026/10/'.$this->chave(),
        ]);

        Storage::disk('local')->put($nfe->xml_path, '<xml>ok</xml>');

        $user = $this->usuarioDoTenant($tenant);
        $resp = $this->actingAs($user)->getJson('/api/nfes/'.$nfe->chave);

        $resp->assertOk();
        $url = $resp->json('data.xml_url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', (string) $url);

        // A URL assinada permite o download (200).
        $this->get($url)->assertOk();

        // Sem assinatura, o download é negado.
        $this->get('/api/nfes/'.$nfe->chave.'/xml')->assertForbidden();
    }

    public function test_url_assinada_expira(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfe = Nfe::factory()->create([
            'tenant_id' => $tenant->id, 'unidade_id' => $unidade->id,
            'xml_path' => 'tenants/1/unidades/1/nfes/2026/10/'.$this->chave(),
        ]);
        Storage::disk('local')->put($nfe->xml_path, '<xml>ok</xml>');

        $url = URL::temporarySignedRoute(
            'api.nfes.xml',
            now()->addMinutes(5),
            ['chave' => $nfe->chave],
        );

        $this->travel(6)->minutes();

        $this->actingAs($this->usuarioDoTenant($tenant))
            ->get($url)
            ->assertForbidden();
    }

    public function test_usuario_sem_permissao_nao_lista(): void
    {
        $tenant = Tenant::factory()->create();

        $semPapel = User::factory()->comTenant($tenant)->create();

        $this->actingAs($semPapel)->getJson('/api/nfes')->assertForbidden();
    }

    private function chave(): string
    {
        return (string) fake()->unique()->numerify(str_repeat('#', 44));
    }
}
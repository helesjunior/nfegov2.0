<?php

namespace Tests\Feature\Api;

use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
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
        $this->seed(RolesSeeder::class);
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

    /**
     * O DANFE (PDF) é gerado a partir do XML completo. Regressão: o controller
     * chamava métodos inexistentes na sped-da (`exibirTextoFatura`/`pdf`) e a
     * geração estourava com "Call to undefined method".
     */
    public function test_gera_danfe_em_pdf_a_partir_do_xml_completo(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $xml = (string) file_get_contents(base_path('tests/Fixtures/nfe_autorizada.xml'));

        $nfe = Nfe::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'chave' => '53261008353835000307550020000014051729330115',
            'estado' => Nfe::ESTADO_COMPLETO,
            'xml_path' => 'tenants/'.$tenant->id.'/unidades/'.$unidade->id.'/nfes/nfe.xml',
        ]);
        Storage::disk('local')->put($nfe->xml_path, $xml);

        $user = $this->usuarioDoTenant($tenant);

        $resp = $this->actingAs($user)->get('/api/nfes/'.$nfe->chave.'/danfe');

        $resp->assertOk();
        $this->assertSame('application/pdf', $resp->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_danfe_sem_xml_resulta_404(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfe = Nfe::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'estado' => Nfe::ESTADO_RESUMO,
            'xml_path' => null,
        ]);

        $this->actingAs($this->usuarioDoTenant($tenant))
            ->get('/api/nfes/'.$nfe->chave.'/danfe')
            ->assertNotFound();
    }

    private function chave(): string
    {
        return (string) fake()->unique()->numerify(str_repeat('#', 44));
    }

    public function test_ordena_por_emissao_mais_recente_por_padrao_com_vazios_no_fim(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id];

        Nfe::factory()->create($base + ['numero' => 'A', 'data_emissao' => '2026-09-01 10:00:00']);
        Nfe::factory()->create($base + ['numero' => 'B', 'data_emissao' => null]);
        Nfe::factory()->create($base + ['numero' => 'C', 'data_emissao' => '2026-10-01 10:00:00']);

        $usuario = $this->usuarioDoTenant($tenant);

        $resp = $this->actingAs($usuario)->getJson('/api/nfes');
        $this->assertSame(['C', 'A', 'B'], array_column($resp->json('data'), 'numero'));

        $resp = $this->actingAs($usuario)->getJson('/api/nfes?ordem=emissao&direcao=asc');
        $this->assertSame(['A', 'C', 'B'], array_column($resp->json('data'), 'numero'));
    }

    public function test_ordena_por_emitente_em_ordem_alfabetica_sem_diferenciar_acento_e_caixa(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id];

        foreach (['Zeta Ltda', 'Água Pura', 'beta sa', 'Ômega'] as $nome) {
            Nfe::factory()->create($base + ['emitente_nome' => $nome]);
        }

        $usuario = $this->usuarioDoTenant($tenant);

        $resp = $this->actingAs($usuario)->getJson('/api/nfes?ordem=emitente&direcao=asc');
        $this->assertSame(['Água Pura', 'beta sa', 'Ômega', 'Zeta Ltda'], array_column($resp->json('data'), 'emitente_nome'));

        $resp = $this->actingAs($usuario)->getJson('/api/nfes?ordem=emitente&direcao=desc');
        $this->assertSame(['Zeta Ltda', 'Ômega', 'beta sa', 'Água Pura'], array_column($resp->json('data'), 'emitente_nome'));
    }

    public function test_ordena_por_valor_e_por_numero_como_numero(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id];

        Nfe::factory()->create($base + ['numero' => '9', 'valor_total' => '1500.00']);
        Nfe::factory()->create($base + ['numero' => '10', 'valor_total' => '20.50']);
        Nfe::factory()->create($base + ['numero' => '100', 'valor_total' => '300.00']);

        $usuario = $this->usuarioDoTenant($tenant);

        $resp = $this->actingAs($usuario)->getJson('/api/nfes?ordem=valor&direcao=desc');
        $this->assertSame(['9', '100', '10'], array_column($resp->json('data'), 'numero'));

        $resp = $this->actingAs($usuario)->getJson('/api/nfes?ordem=numero&direcao=asc');
        $this->assertSame(['9', '10', '100'], array_column($resp->json('data'), 'numero'));
    }

    public function test_ordenacao_mantem_filtros_e_paginacao(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $base = ['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id];

        foreach ([100, 200, 300, 400] as $valor) {
            Nfe::factory()->create($base + ['valor_total' => $valor, 'data_emissao' => '2026-10-10 10:00:00']);
        }
        Nfe::factory()->create($base + ['valor_total' => 999, 'data_emissao' => '2026-08-10 10:00:00']);

        $resp = $this->actingAs($this->usuarioDoTenant($tenant))
            ->getJson('/api/nfes?de=2026-10-01&ordem=valor&direcao=asc&per_page=2&page=2');

        $resp->assertOk();
        $this->assertSame(4, $resp->json('meta.total'));
        $this->assertSame(['300.00', '400.00'], array_column($resp->json('data'), 'valor_total'));
    }

    public function test_ordem_fora_da_lista_branca_e_recusada(): void
    {
        $tenant = Tenant::factory()->create();
        $usuario = $this->usuarioDoTenant($tenant);

        $this->actingAs($usuario)->getJson('/api/nfes?ordem=xml_path')
            ->assertStatus(422)->assertJsonValidationErrors('ordem');
        $this->actingAs($usuario)->getJson('/api/nfes?ordem=emissao&direcao=lado')
            ->assertStatus(422)->assertJsonValidationErrors('direcao');
    }
}

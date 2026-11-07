<?php

namespace Tests\Feature\Nfe;

use App\Domain\Nfe\NfeImporter;
use App\Domain\Nfe\NfeQuery;
use App\Jobs\ExportNfeJob;
use App\Jobs\ExportXmlJob;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * A NF-e pertence à UNIDADE (CNPJ que a recebeu), não à entidade.
 *
 * Regressão: com a chave única por entidade, a mesma nota recebida por duas
 * unidades (ex.: transferência entre filiais) "pulava" de uma para a outra e
 * a primeira unidade perdia o registro.
 */
class NfePorUnidadeTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35261099888777000166550010000001231000001230';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        TenantContext::clear();
        parent::tearDown();
    }

    /**
     * @return array{0: Tenant, 1: Unidade, 2: Unidade}
     */
    private function entidadeComDuasUnidades(): array
    {
        $tenant = Tenant::factory()->create();
        $matriz = Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000181', 'nome' => 'Matriz', 'nome_resumido' => 'MATRIZ']);
        $filial = Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000262', 'nome' => 'Filial', 'nome_resumido' => 'FILIAL']);

        return [$tenant, $matriz, $filial];
    }

    private function operador(Tenant $tenant): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole('operador');

        return $u;
    }

    private function xml(string $chave = self::CHAVE): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe{$chave}" versao="4.00">
      <ide><natOp>TRANSFERENCIA</natOp><mod>55</mod><serie>1</serie><nNF>123</nNF><dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>
      <emit><CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ Ltda</xNome></emit>
      <dest><CNPJ>11222333000181</CNPJ></dest>
      <total><ICMSTot><vNF>25.00</vNF></ICMSTot></total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    private function resumo(string $chave = self::CHAVE): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
  <chNFe>{$chave}</chNFe><CNPJ>99888777000166</CNPJ><xNome>Fornecedor XYZ Ltda</xNome>
  <dhEmi>2026-10-01T10:00:00-03:00</dhEmi><vNF>25.00</vNF>
</resNFe>
XML;
    }

    private function importar(Unidade $unidade, string $xml): Nfe
    {
        return TenantContext::runAs($unidade->tenant_id, fn () => app(NfeImporter::class)->import($unidade, null, $xml));
    }

    public function test_mesma_nota_em_duas_unidades_gera_um_registro_e_um_xml_por_unidade(): void
    {
        [, $matriz, $filial] = $this->entidadeComDuasUnidades();

        $a = $this->importar($matriz, $this->xml());
        $b = $this->importar($filial, $this->xml());

        $this->assertNotSame($a->id, $b->id);
        $this->assertSame(2, Nfe::withoutGlobalScopes()->where('chave', self::CHAVE)->count());

        // A matriz não perdeu a nota para a filial.
        $this->assertSame($matriz->id, $a->fresh()->unidade_id);
        $this->assertStringContainsString('/unidades/'.$matriz->id.'/', $a->fresh()->xml_path);
        $this->assertStringContainsString('/unidades/'.$filial->id.'/', $b->xml_path);
        Storage::disk('local')->assertExists($a->fresh()->xml_path);
        Storage::disk('local')->assertExists($b->xml_path);
    }

    public function test_resumo_tardio_nao_rebaixa_nota_completa(): void
    {
        [, $matriz] = $this->entidadeComDuasUnidades();

        $this->importar($matriz, $this->xml());
        TenantContext::runAs($matriz->tenant_id, fn () => app(NfeImporter::class)->importResumo($matriz, null, $this->resumo()));

        $nfe = Nfe::withoutGlobalScopes()->where('unidade_id', $matriz->id)->where('chave', self::CHAVE)->first();
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
        $this->assertNotNull($nfe->xml_path);
    }

    public function test_listagem_traz_a_unidade_e_filtra_por_unidade(): void
    {
        [$tenant, $matriz, $filial] = $this->entidadeComDuasUnidades();
        Nfe::factory()->count(2)->create(['tenant_id' => $tenant->id, 'unidade_id' => $matriz->id]);
        Nfe::factory()->create(['tenant_id' => $tenant->id, 'unidade_id' => $filial->id]);

        $user = $this->operador($tenant);

        $todas = $this->actingAs($user)->getJson('/api/nfes')->assertOk();
        $this->assertSame(3, $todas->json('meta.total'));
        $this->assertNotNull($todas->json('data.0.unidade.cnpj'));

        $soFilial = $this->actingAs($user)->getJson('/api/nfes?unidade_id='.$filial->id)->assertOk();
        $this->assertSame(1, $soFilial->json('meta.total'));
        $this->assertSame('FILIAL', $soFilial->json('data.0.unidade.nome_resumido'));
        $this->assertSame('11222333000262', $soFilial->json('data.0.unidade.cnpj'));
    }

    public function test_filtro_com_unidade_de_outra_entidade_nao_vaza_notas(): void
    {
        [$tenant] = $this->entidadeComDuasUnidades();
        $outro = Tenant::factory()->create();
        $alheia = Unidade::factory()->create(['tenant_id' => $outro->id]);
        Nfe::factory()->create(['tenant_id' => $outro->id, 'unidade_id' => $alheia->id]);

        $resp = $this->actingAs($this->operador($tenant))->getJson('/api/nfes?unidade_id='.$alheia->id);

        $resp->assertOk();
        $this->assertSame(0, $resp->json('meta.total'));
    }

    public function test_detalhe_e_xml_respeitam_a_unidade_escolhida(): void
    {
        [$tenant, $matriz, $filial] = $this->entidadeComDuasUnidades();
        $this->importar($matriz, $this->xml());
        $b = $this->importar($filial, $this->xml());

        $user = $this->operador($tenant);

        $resp = $this->actingAs($user)->getJson('/api/nfes/'.self::CHAVE.'?unidade_id='.$filial->id)->assertOk();
        $this->assertSame($filial->id, $resp->json('data.unidade_id'));
        $this->assertSame('FILIAL', $resp->json('data.unidade.nome_resumido'));

        // A URL assinada carrega a unidade e entrega o XML da filial.
        $url = (string) $resp->json('data.xml_url');
        $this->assertStringContainsString('unidade_id='.$filial->id, $url);
        $this->get($url)->assertOk();
        $this->assertSame($filial->id, $b->unidade_id);

        // Sem unidade: devolve o primeiro registro (links antigos seguem válidos).
        $this->actingAs($user)->getJson('/api/nfes/'.self::CHAVE)
            ->assertOk()
            ->assertJsonPath('data.unidade_id', $matriz->id);
    }

    public function test_export_csv_traz_nome_e_cnpj_da_unidade(): void
    {
        Notification::fake();
        [$tenant, , $filial] = $this->entidadeComDuasUnidades();
        Nfe::factory()->create(['tenant_id' => $tenant->id, 'unidade_id' => $filial->id]);
        $user = $this->operador($tenant);

        (new ExportNfeJob($tenant->id, $user->id, [], 'csv'))->handle(app(NfeQuery::class));

        $arquivo = Storage::disk('local')->files('exports/'.$tenant->id)[0];
        $conteudo = Storage::disk('local')->get($arquivo);

        $this->assertStringContainsString('Unidade CNPJ', $conteudo);
        $this->assertStringContainsString('FILIAL', $conteudo);
        $this->assertStringContainsString('11222333000262', $conteudo);
    }

    public function test_export_zip_separa_xmls_em_pastas_por_unidade(): void
    {
        Notification::fake();
        [$tenant, $matriz, $filial] = $this->entidadeComDuasUnidades();
        $this->importar($matriz, $this->xml());
        $this->importar($filial, $this->xml());
        $user = $this->operador($tenant);

        (new ExportXmlJob($tenant->id, $user->id, []))->handle(app(NfeQuery::class));

        $zipPath = Storage::disk('local')->path(Storage::disk('local')->files('exports/'.$tenant->id)[0]);
        $zip = new ZipArchive();
        $zip->open($zipPath);

        $this->assertSame(2, $zip->numFiles);
        $this->assertNotFalse($zip->locateName('11222333000181/'.self::CHAVE.'.xml'));
        $this->assertNotFalse($zip->locateName('11222333000262/'.self::CHAVE.'.xml'));
        $zip->close();
    }

    public function test_comando_recria_registro_perdido_sem_alterar_o_xml(): void
    {
        [$tenant, $matriz, $filial] = $this->entidadeComDuasUnidades();

        // Situação deixada pelo bug: o XML da matriz está no disco, mas o único
        // registro foi "levado" pela filial (via resumo, apontando o XML da matriz).
        $caminhoMatriz = app(\App\Domain\Nfe\XmlStore::class)->caminho($tenant, $matriz, self::CHAVE);
        Storage::disk('local')->put($caminhoMatriz, $this->xml());
        $original = Storage::disk('local')->get($caminhoMatriz);

        Nfe::factory()->resumo()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $filial->id,
            'chave' => self::CHAVE,
            'xml_path' => $caminhoMatriz,
        ]);

        $this->artisan('nfe:reconciliar-unidades')->assertSuccessful();

        $daMatriz = Nfe::withoutGlobalScopes()->where('unidade_id', $matriz->id)->where('chave', self::CHAVE)->first();
        $this->assertNotNull($daMatriz);
        $this->assertSame($caminhoMatriz, $daMatriz->xml_path);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $daMatriz->estado);

        $daFilial = Nfe::withoutGlobalScopes()->where('unidade_id', $filial->id)->where('chave', self::CHAVE)->first();
        $this->assertNull($daFilial->xml_path);

        // O arquivo continua intacto, no mesmo lugar.
        $this->assertSame($original, Storage::disk('local')->get($caminhoMatriz));

        // Idempotente.
        $this->artisan('nfe:reconciliar-unidades')->assertSuccessful();
        $this->assertSame(2, Nfe::withoutGlobalScopes()->where('chave', self::CHAVE)->count());
    }

    public function test_comando_em_simulacao_nao_grava_nada(): void
    {
        [$tenant, $matriz] = $this->entidadeComDuasUnidades();
        Storage::disk('local')->put(app(\App\Domain\Nfe\XmlStore::class)->caminho($tenant, $matriz, self::CHAVE), $this->xml());

        $this->artisan('nfe:reconciliar-unidades', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }
}

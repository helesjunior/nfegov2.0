<?php

namespace Tests\Feature\Nfse;

use App\Domain\Nfse\NfseImporter;
use App\Models\Nfse;
use App\Models\NfseEvento;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Leitura do XML da NFS-e nacional, papel da unidade, eventos e guarda do XML.
 */
class NfseImporterTest extends TestCase
{
    use NfseXml;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function unidade(string $cnpj = '11222333000181', ?Tenant $tenant = null): Unidade
    {
        $tenant ??= Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => $cnpj]);
    }

    public function test_importa_campos_da_nfse_e_grava_o_xml_na_pasta_da_unidade(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse(aamm: '2310');

        $nfse = app(NfseImporter::class)->importarNfse($unidade, $this->xmlNfse($chave, extra: ['vISSQN' => '100.00', 'tpRetISSQN' => '2']), 15);

        $this->assertSame($chave, $nfse->chave);
        $this->assertSame(15, $nfse->nsu);
        $this->assertSame(Nfse::PAPEL_TOMADOR, $nfse->papel);
        $this->assertSame('12', $nfse->numero);
        $this->assertSame('99888777000166', $nfse->prestador_documento);
        $this->assertSame('Prestador de Teste Ltda', $nfse->prestador_nome);
        $this->assertSame('11222333000181', $nfse->tomador_documento);
        $this->assertSame('Brasília', $nfse->municipio_emissor_nome);
        $this->assertSame('2023-10-20', $nfse->competencia->format('Y-m-d'));
        $this->assertSame('141302', $nfse->codigo_servico);
        $this->assertSame('5000.00', $nfse->valor_servico);
        $this->assertSame('4800.00', $nfse->valor_liquido);
        $this->assertSame('100.00', $nfse->valor_iss);
        $this->assertTrue($nfse->iss_retido);
        $this->assertSame('1.01', $nfse->versao_leiaute);
        $this->assertSame(Nfse::SITUACAO_NORMAL, $nfse->situacao);

        $esperado = "tenants/{$unidade->tenant_id}/unidades/{$unidade->id}/nfses/2023/10/{$chave}.xml";
        $this->assertSame($esperado, $nfse->xml_path);
        Storage::disk('local')->assertExists($esperado);
    }

    public function test_papel_prestador_e_intermediario(): void
    {
        $importer = app(NfseImporter::class);

        $prestadora = $this->unidade('99888777000166');
        $chave = $this->chaveNfse();
        $this->assertSame(Nfse::PAPEL_PRESTADOR, $importer->importarNfse($prestadora, $this->xmlNfse($chave))->papel);

        $intermediaria = $this->unidade('55444333000122');
        $chave2 = $this->chaveNfse(numero: 2);
        $this->assertSame(
            Nfse::PAPEL_INTERMEDIARIO,
            $importer->importarNfse($intermediaria, $this->xmlNfse($chave2, extra: ['interm' => '55444333000122']))->papel,
        );
    }

    public function test_reimportar_nao_duplica(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $xml = $this->xmlNfse($chave);

        app(NfseImporter::class)->importarNfse($unidade, $xml, 1);
        app(NfseImporter::class)->importarNfse($unidade, $xml, 1);

        $this->assertSame(1, Nfse::count());
    }

    public function test_mesma_chave_em_duas_unidades_da_entidade(): void
    {
        $tenant = Tenant::factory()->create();
        $matriz = $this->unidade('11222333000181', $tenant);
        $filial = $this->unidade('11222333000262', $tenant);
        $chave = $this->chaveNfse();

        app(NfseImporter::class)->importarNfse($matriz, $this->xmlNfse($chave), 1);
        app(NfseImporter::class)->importarNfse($filial, $this->xmlNfse($chave), 9);

        $this->assertSame(2, Nfse::where('chave', $chave)->count());
    }

    public function test_xml_nunca_e_sobrescrito_por_conteudo_diferente(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();

        app(NfseImporter::class)->importarNfse($unidade, $this->xmlNfse($chave), 1);

        $this->expectException(RuntimeException::class);
        app(NfseImporter::class)->importarNfse($unidade, $this->xmlNfse($chave, extra: ['vISSQN' => '1.00']), 1);
    }

    public function test_cancelamento_antes_da_nota_e_ligado_quando_ela_chega(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $importer = app(NfseImporter::class);

        $evento = $importer->importarEvento($unidade, $this->xmlCancelamento($chave), 5);
        $this->assertNull($evento->nfse_id);
        $this->assertSame('CANCELAMENTO', $evento->tipo_evento);
        $this->assertSame('101101', $evento->codigo_evento);
        $this->assertSame('erro no preenchimento do tomador', $evento->motivo);

        $nfse = $importer->importarNfse($unidade, $this->xmlNfse($chave), 4);

        $this->assertSame(Nfse::SITUACAO_CANCELADA, $nfse->situacao);
        $this->assertSame($nfse->id, NfseEvento::first()->nfse_id);
        Storage::disk('local')->assertExists(
            "tenants/{$unidade->tenant_id}/unidades/{$unidade->id}/nfses/2023/10/eventos/{$chave}-5.xml"
        );
    }

    public function test_substituicao_marca_a_nota_como_substituida(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $importer = app(NfseImporter::class);

        $importer->importarNfse($unidade, $this->xmlNfse($chave), 1);
        $importer->importarEvento($unidade, $this->xmlCancelamento($chave), 2, 'CANCELAMENTO_POR_SUBSTITUICAO');

        $this->assertSame(Nfse::SITUACAO_SUBSTITUIDA, Nfse::first()->situacao);
    }

    public function test_xml_sem_chave_valida_e_recusado(): void
    {
        $this->expectException(RuntimeException::class);

        app(NfseImporter::class)->importarNfse($this->unidade(), $this->xmlNfse('123'));
    }
}

<?php

namespace Tests\Unit\Sefaz;

use App\Domain\Certificate\CertificateLoader;
use App\Domain\Sefaz\ConsultaService;
use App\Domain\Sefaz\SefazConfig;
use App\Domain\Sefaz\ToolsFactory;
use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use NFePHP\NFe\Tools;
use Tests\TestCase;

/**
 * Montagem do Tools do sped-nfe a partir da unidade e do certificado.
 */
class ToolsFactoryTest extends TestCase
{
    use RefreshDatabase;

    private function unidadeComCertificado(array $attrs = [], int $tpAmb = 2): Unidade
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
            'ie' => '123456789',
            'nome' => 'Unidade Teste',
            'uf' => 'SP',
            'tp_amb' => $tpAmb,
        ], $attrs));

        app(\App\Domain\Certificate\CertificateService::class)->store(
            $unidade,
            new UploadedFile(
                base_path('tests/Fixtures/cert_e_cnpj.pfx'),
                'certificado.pfx',
                'application/x-pkcs12',
                null,
                true
            ),
            'senha-pfx'
        );

        return $unidade->fresh();
    }

    public function test_factory_monta_tools_com_config_da_unidade(): void
    {
        $unidade = $this->unidadeComCertificado();

        $tools = app(ToolsFactory::class)->make($unidade);

        $this->assertInstanceOf(Tools::class, $tools);
        $this->assertSame(55, $tools->model());
    }

    public function test_config_usa_dados_da_unidade(): void
    {
        $unidade = $this->unidadeComCertificado();

        $config = app(SefazConfig::class)->paraUnidade($unidade);

        $this->assertSame('11222333000181', $config['cnpj']);
        $this->assertSame('123456789', $config['ie']);
        $this->assertSame('SP', $config['siglaUF']);
        $this->assertSame('4.00', $config['versao']);
        $this->assertSame(2, $config['tpAmb']);
        $this->assertArrayHasKey('schemes', $config);
    }

    public function test_config_reflete_ambiente_de_producao(): void
    {
        $unidade = $this->unidadeComCertificado(['tp_amb' => 1], tpAmb: 1);

        $config = app(SefazConfig::class)->paraUnidade($unidade);

        $this->assertSame(1, $config['tpAmb']);
    }

    public function test_monta_em_homologacao_quando_unidade_sem_certificado_e_erro(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        // Sem certificado ativo: deve falhar de forma clara.
        $this->expectException(\RuntimeException::class);

        app(ToolsFactory::class)->make($unidade);
    }

    public function test_consulta_por_chave_devolve_xml_de_retorno(): void
    {
        $unidade = $this->unidadeComCertificado();
        $chave = str_repeat('1', 44);

        // Mock do Tools: não toca a SEFAZ; devolve um XML de retorno simulado.
        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazConsultaChave')
            ->once()
            ->with($chave, 2)
            ->andReturn('<retConsSitNFe><cStat>100</cStat></retConsSitNFe>');

        $xml = app(ConsultaService::class)->consultarCom($tools, $unidade, $chave);

        $this->assertStringContainsString('<cStat>100</cStat>', $xml);
    }
}
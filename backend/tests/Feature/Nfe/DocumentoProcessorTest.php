<?php

namespace Tests\Feature\Nfe;

use App\Domain\Nfe\DocumentoProcessor;
use App\Domain\Sefaz\DownloadService;
use App\Domain\Sefaz\ManifestacaoService;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * O `DocumentoProcessor` transforma UM documento do distDFe (docZip) em nota.
 *
 * É compartilhado pelo laço incremental (`DistDfeService`) e pela recuperação
 * pontual (`RecuperacaoService`). Não mexe no cursor `Nsu` da unidade.
 */
class DocumentoProcessorTest extends TestCase
{
    use RefreshDatabase;

    private function doc(string $xml, string $schema = 'procNFe', int $nsu = 10): array
    {
        return [
            'nsu' => $nsu,
            'schema' => $schema,
            'conteudo' => base64_encode(gzencode($xml)),
        ];
    }

    private function xmlNfeProc(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe'.$chave
            .'" versao="4.00"><ide><nNF>1</nNF><serie>1</serie><mod>55</mod>'
            .'<dhEmi>2026-10-01T10:00:00-03:00</dhEmi></ide>'
            .'<emit><CNPJ>11111111111111</CNPJ><xNome>Fornecedor</xNome></emit>'
            .'<dest><CNPJ>00101980000119</CNPJ></dest>'
            .'<total><ICMSTot><vNF>100.00</vNF></ICMSTot></total></infNFe></NFe>'
            .'<protNFe><infProt><cStat>100</cStat></infProt></protNFe></nfeProc>';
    }

    private function xmlResumo(string $chave): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<resNFe xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.$chave.'</chNFe>'
            .'<CNPJ>11111111111111</CNPJ><xNome>Fornecedor</xNome><vNF>100.00</vNF>'
            .'<dhEmi>2026-10-01T10:00:00-03:00</dhEmi></resNFe>';
    }

    public function test_procnfe_importa(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $chave = str_repeat('1', 44);

        app(DocumentoProcessor::class)->processar($unidade, null, $this->doc($this->xmlNfeProc($chave)));

        $this->assertNotNull(Nfe::withoutGlobalScopes()->where('chave', $chave)->first());
    }

    public function test_evento_e_ignorado(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $xml = '<?xml version="1.0"?><resEvento xmlns="http://www.portalfiscal.inf.br/nfe"><chNFe>'.str_repeat('2', 44).'</chNFe></resEvento>';

        app(DocumentoProcessor::class)->processar($unidade, null, $this->doc($xml, 'resEvento'));

        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());
    }

    /**
     * A SEFAZ em PRODUÇÃO envia o schema com sufixo de versão
     * (`procNFe_v4.00.xsd`, `resNFe_v1.01.xsd`), não o nome "puro".
     */
    public function test_procnfe_com_sufixo_de_versao_importa(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $chave = str_repeat('4', 44);

        app(DocumentoProcessor::class)->processar(
            $unidade,
            null,
            $this->doc($this->xmlNfeProc($chave), 'procNFe_v4.00.xsd'),
        );

        $nfe = Nfe::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($nfe);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
    }

    public function test_resumo_com_sufixo_de_versao_dispara_ciencia_e_baixa_completo(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $chave = str_repeat('5', 44);

        $manifestacao = \Mockery::mock(ManifestacaoService::class);
        $manifestacao->shouldReceive('ciencia')->once()->withArgs(fn ($u, $c) => $c === $chave)->andReturn(true);
        app()->instance(ManifestacaoService::class, $manifestacao);

        $download = \Mockery::mock(DownloadService::class);
        $download->shouldReceive('porChave')->once()->andReturn($this->xmlNfeProc($chave));
        app()->instance(DownloadService::class, $download);

        app(DocumentoProcessor::class)->processar(
            $unidade,
            null,
            $this->doc($this->xmlResumo($chave), 'resNFe_v1.01.xsd'),
        );

        $nfe = Nfe::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($nfe);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
    }

    public function test_resumo_dispara_ciencia_e_baixa_completo(): void
    {
        Storage::fake('local');
        $unidade = Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $chave = str_repeat('3', 44);

        $manifestacao = \Mockery::mock(ManifestacaoService::class);
        $manifestacao->shouldReceive('ciencia')->once()->withArgs(fn ($u, $c) => $c === $chave)->andReturn(true);
        app()->instance(ManifestacaoService::class, $manifestacao);

        $download = \Mockery::mock(DownloadService::class);
        $download->shouldReceive('porChave')->once()->andReturn($this->xmlNfeProc($chave));
        app()->instance(DownloadService::class, $download);

        app(DocumentoProcessor::class)->processar($unidade, null, $this->doc($this->xmlResumo($chave), 'resNFe'));

        $nfe = Nfe::withoutGlobalScopes()->where('chave', $chave)->first();
        $this->assertNotNull($nfe);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
    }
}

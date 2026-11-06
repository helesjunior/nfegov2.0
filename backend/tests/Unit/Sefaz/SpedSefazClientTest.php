<?php

namespace Tests\Unit\Sefaz;

use App\Domain\Sefaz\SpedSefazClient;
use App\Domain\Sefaz\ToolsFactory;
use App\Models\Unidade;
use NFePHP\NFe\Tools;
use Tests\TestCase;

/**
 * Regressão: a consulta incremental do distDFe precisa apontar para o
 * Ambiente Nacional ("AN").
 *
 * O `sefazDistDFe` do sped-nfe repassa o argumento `fonte` ao resolvedor de
 * autorizador; se vier vazio, o autorizador não é encontrado e a chamada
 * falha com "Nao existe autorizador [] para os webservices do modelo [55]".
 * Sem tocar a SEFAZ, garantimos que a fonte correta é passada.
 */
class SpedSefazClientTest extends TestCase
{
    public function test_dist_dfe_usa_ambiente_nacional_como_fonte(): void
    {
        $unidade = new Unidade(['uf' => 'DF', 'cnpj' => '30135801000125', 'tp_amb' => 1]);

        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazDistDFe')
            ->once()
            ->with(123, 0, '', 'AN')
            ->andReturn('<retDistDFeInt/>');

        $factory = \Mockery::mock(ToolsFactory::class);

        $client = new SpedSefazClient($factory);

        $this->assertSame('<retDistDFeInt/>', $client->distDFeCom($tools, $unidade, 123));
    }

    public function test_dist_dfe_por_nsu_usa_consnsu(): void
    {
        $unidade = new Unidade(['uf' => 'DF', 'cnpj' => '00101980000119', 'tp_amb' => 1]);

        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazDistDFe')
            ->once()
            ->with(0, 12345, '', 'AN')
            ->andReturn('<retDistDFeInt/>');

        $factory = \Mockery::mock(ToolsFactory::class);

        $client = new SpedSefazClient($factory);

        $this->assertSame('<retDistDFeInt/>', $client->distDFePorNsuCom($tools, $unidade, 12345));
    }

    public function test_dist_dfe_por_chave_usa_conschnfe(): void
    {
        $chave = str_repeat('9', 44);
        $unidade = new Unidade(['uf' => 'DF', 'cnpj' => '00101980000119', 'tp_amb' => 1]);

        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazDistDFe')
            ->once()
            ->with(0, 0, $chave, 'AN')
            ->andReturn('<retDistDFeInt/>');

        $factory = \Mockery::mock(ToolsFactory::class);

        $client = new SpedSefazClient($factory);

        $this->assertSame('<retDistDFeInt/>', $client->distDFePorChaveCom($tools, $unidade, $chave));
    }

    /**
     * `sefazDownload` devolve o `retDistDFeInt` (envelope SOAP) com o XML
     * completo dentro de um `docZip` (base64+gzip) — NÃO o `nfeProc` cru.
     * O cliente precisa decodificar o `docZip` e devolver o `nfeProc`, que é o
     * que o `NfeImporter` espera (senão: "XML sem chave de acesso válida").
     */
    public function test_baixar_por_chave_decodifica_o_doczip_do_download(): void
    {
        $chave = str_repeat('9', 44);
        $unidade = new Unidade(['uf' => 'DF', 'cnpj' => '00101980000119', 'tp_amb' => 1]);

        $nfeProc = '<?xml version="1.0"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            .'<NFe><infNFe Id="NFe'.$chave.'" versao="4.00"><ide><nNF>1</nNF></ide>'
            .'<emit><CNPJ>11111111111111</CNPJ></emit></infNFe></NFe></nfeProc>';

        $resposta = '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">'
            .'<soap:Body><nfeDistDFeInteresseResponse xmlns="http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe">'
            .'<nfeDistDFeInteresseResult><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<cStat>138</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<loteDistDFeInt><docZip NSU="1" schema="procNFe_v4.00.xsd">'
            .base64_encode(gzencode($nfeProc))
            .'</docZip></loteDistDFeInt></retDistDFeInt>'
            .'</nfeDistDFeInteresseResult></nfeDistDFeInteresseResponse></soap:Body></soap:Envelope>';

        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazDownload')->once()->with($chave)->andReturn($resposta);

        $factory = \Mockery::mock(ToolsFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($tools);

        $client = new SpedSefazClient($factory);

        $xml = $client->baixarPorChave($unidade, $chave);

        $this->assertNotNull($xml);
        $this->assertStringContainsString('<nfeProc', $xml);
        $this->assertStringContainsString($chave, $xml);
        $this->assertStringNotContainsString('docZip', $xml);
    }

    /**
     * Quando a SEFAZ devolve `cStat` sem documento (ex.: 137), o download
     * não tem XML: o cliente devolve `null`.
     */
    public function test_baixar_por_chave_sem_documento_retorna_null(): void
    {
        $chave = str_repeat('8', 44);
        $unidade = new Unidade(['uf' => 'DF', 'cnpj' => '00101980000119', 'tp_amb' => 1]);

        $resposta = '<retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">'
            .'<cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo></retDistDFeInt>';

        $tools = \Mockery::mock(Tools::class);
        $tools->shouldReceive('sefazDownload')->once()->with($chave)->andReturn($resposta);

        $factory = \Mockery::mock(ToolsFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($tools);

        $client = new SpedSefazClient($factory);

        $this->assertNull($client->baixarPorChave($unidade, $chave));
    }
}

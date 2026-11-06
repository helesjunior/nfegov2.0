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
}
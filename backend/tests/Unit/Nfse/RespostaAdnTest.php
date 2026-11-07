<?php

namespace Tests\Unit\Nfse;

use App\Domain\Nfse\RespostaAdn;
use PHPUnit\Framework\TestCase;

/**
 * Interpretação das respostas do ADN, com os casos vistos em chamadas reais.
 */
class RespostaAdnTest extends TestCase
{
    public function test_fila_vazia_404_nao_e_erro(): void
    {
        $r = RespostaAdn::deCorpo(404, '{"StatusProcessamento":"NENHUM_DOCUMENTO_LOCALIZADO","LoteDFe":[],"Erros":[{"Codigo":"E2220","Descricao":"Nenhum documento localizado"}]}');

        $this->assertTrue($r->filaVazia());
        $this->assertFalse($r->deveAbrirFreio());
    }

    public function test_lote_com_documentos(): void
    {
        $r = RespostaAdn::deCorpo(200, '{"StatusProcessamento":"DOCUMENTOS_LOCALIZADOS","LoteDFe":[{"NSU":1,"ChaveAcesso":"x","TipoDocumento":"NFSE","ArquivoXml":"abc","DataHoraGeracao":"2023-05-12T06:59:50.547"}],"Erros":[]}');

        $this->assertTrue($r->sucesso());
        $this->assertSame(1, $r->documentos[0]['nsu']);
        $this->assertSame('NFSE', $r->documentos[0]['tipo_documento']);
        $this->assertNull($r->documentos[0]['tipo_evento']);
    }

    public function test_http_400_com_erros_e_status_de_sucesso_nao_e_sucesso(): void
    {
        $r = RespostaAdn::deCorpo(400, '{"StatusProcessamento":"DOCUMENTOS_LOCALIZADOS","LoteDFe":[],"Erros":[{"Codigo":"E2243","Descricao":"CNPJ base diferente"}]}');

        $this->assertFalse($r->sucesso());
        $this->assertFalse($r->filaVazia());
        $this->assertSame('E2243: CNPJ base diferente', $r->mensagemErro());
    }

    public function test_corpo_que_nao_e_do_adn_vira_erro(): void
    {
        $r = RespostaAdn::deCorpo(400, '{"type":"https://tools.ietf.org/html/rfc9110","title":"One or more validation errors occurred."}');

        $this->assertFalse($r->sucesso());
        $this->assertNull($r->status);
        $this->assertStringContainsString('Resposta inesperada do ADN', $r->mensagemErro());
    }

    public function test_429_e_503_abrem_o_freio(): void
    {
        $this->assertTrue(RespostaAdn::deCorpo(429, '')->deveAbrirFreio());
        $this->assertTrue(RespostaAdn::deCorpo(503, '<html>')->deveAbrirFreio());
    }
}

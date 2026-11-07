<?php

namespace Tests\Unit\Nfse;

use App\Domain\Nfse\RespostaSefin;
use PHPUnit\Framework\TestCase;

/**
 * Interpretação das respostas da SEFIN Nacional ao pedido de evento.
 */
class RespostaSefinTest extends TestCase
{
    public function test_sucesso_201_traz_o_evento_descompactado(): void
    {
        $xml = '<evento versao="1.01"/>';
        $r = RespostaSefin::deCorpo(201, json_encode([
            'tipoAmbiente' => 1,
            'eventoXmlGZipB64' => base64_encode(gzencode($xml)),
        ]));

        $this->assertTrue($r->registrado());
        $this->assertSame($xml, $r->eventoXml);
    }

    public function test_erro_como_array_como_a_sefin_responde_de_verdade(): void
    {
        $r = RespostaSefin::deCorpo(400, '{"erro":[{"codigo":"E0717","descricao":"A assinatura é obrigatória."}]}');

        $this->assertFalse($r->registrado());
        $this->assertSame('E0717: A assinatura é obrigatória.', $r->mensagemErro());
    }

    public function test_erro_como_objeto_como_o_swagger_documenta(): void
    {
        $r = RespostaSefin::deCorpo(400, '{"erro":{"codigo":"E1831","descricao":"NFS-e inexistente","complemento":"x"}}');

        $this->assertSame('E1831: NFS-e inexistente', $r->mensagemErro());
    }

    public function test_201_com_erros_nao_conta_como_registrado(): void
    {
        $r = RespostaSefin::deCorpo(201, '{"erro":[{"codigo":"X","descricao":"y"}]}');

        $this->assertFalse($r->registrado());
    }

    public function test_corpo_que_nao_e_json_vira_erro_legivel(): void
    {
        $r = RespostaSefin::deCorpo(503, '<html>Service Unavailable</html>');

        $this->assertFalse($r->registrado());
        $this->assertStringContainsString('Resposta inesperada da SEFIN', $r->mensagemErro());
    }

    public function test_evento_corrompido_nao_derruba_a_leitura(): void
    {
        $r = RespostaSefin::deCorpo(201, '{"eventoXmlGZipB64":"nao-e-gzip"}');

        $this->assertNull($r->eventoXml);
    }
}

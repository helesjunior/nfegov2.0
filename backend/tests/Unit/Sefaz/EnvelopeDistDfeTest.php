<?php

namespace Tests\Unit\Sefaz;

use App\Domain\Sefaz\EnvelopeDistDfe;
use PHPUnit\Framework\TestCase;

/**
 * O `EnvelopeDistDfe` interpreta o `retDistDFeInt`, usado tanto pelo laço
 * incremental quanto pela recuperação pontual.
 */
class EnvelopeDistDfeTest extends TestCase
{
    public function test_extrai_cstat_nsu_e_docs(): void
    {
        $xml = '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>138</cStat><xMotivo>Documento localizado</xMotivo>'
            .'<ultNSU>000000000000150</ultNSU><maxNSU>000000000000200</maxNSU>'
            .'<loteDistDFeInt><docZip NSU="150" schema="procNFe">QUJD</docZip></loteDistDFeInt>'
            .'</retDistDFeInt>';

        $env = (new EnvelopeDistDfe)->parse($xml);

        $this->assertSame('138', $env['cStat']);
        $this->assertSame(150, $env['ultNSU']);
        $this->assertSame(200, $env['maxNSU']);
        $this->assertCount(1, $env['docs']);
        $this->assertSame(150, $env['docs'][0]['nsu']);
        $this->assertSame('procNFe', $env['docs'][0]['schema']);
        $this->assertSame('QUJD', $env['docs'][0]['conteudo']);
    }

    public function test_vazio_quando_sem_documentos(): void
    {
        $xml = '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>137</cStat><xMotivo>Nenhum documento localizado</xMotivo>'
            .'<ultNSU>000000000000100</ultNSU><maxNSU>000000000000100</maxNSU></retDistDFeInt>';

        $env = (new EnvelopeDistDfe)->parse($xml);

        $this->assertSame('137', $env['cStat']);
        $this->assertSame([], $env['docs']);
    }
}

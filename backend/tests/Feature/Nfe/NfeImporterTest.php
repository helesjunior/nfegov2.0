<?php

namespace Tests\Feature\Nfe;

use App\Domain\Nfe\NfeImporter;
use App\Domain\Nfe\XmlStore;
use App\Models\Nfe;
use App\Domain\Sefaz\DocZipDecoder;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Persistência de NF-e e armazenamento imutável de XML.
 */
class NfeImporterTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id]);
    }

    private function xmlNfeCompleta(string $chave, string $numero = '123'): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
  <NFe>
    <infNFe Id="NFe{$chave}" versao="4.00">
      <ide>
        <cUF>35</cUF>
        <natOp>COMPRA</natOp>
        <mod>55</mod>
        <serie>1</serie>
        <nNF>{$numero}</nNF>
        <dhEmi>2026-10-01T10:00:00-03:00</dhEmi>
      </ide>
      <emit>
        <CNPJ>99888777000166</CNPJ>
        <xNome>Fornecedor XYZ Ltda</xNome>
        <IE>111222333</IE>
      </emit>
      <dest>
        <CNPJ>11222333000181</CNPJ>
      </dest>
      <det nItem="1">
        <prod>
          <cProd>001</cProd>
          <xProd>Caneta esferográfica</xProd>
          <CFOP>5102</CFOP>
          <uCom>UN</uCom>
          <qCom>10.0000</qCom>
          <vUnCom>2.5000</vUnCom>
          <vProd>25.00</vProd>
        </prod>
      </det>
      <total>
        <ICMSTot>
          <vNF>25.00</vNF>
        </ICMSTot>
      </total>
    </infNFe>
  </NFe>
</nfeProc>
XML;
    }

    private function xmlResumo(string $chave): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.01">
  <chNFe>{$chave}</chNFe>
  <CNPJ>99888777000166</CNPJ>
  <xNome>Fornecedor XYZ Ltda</xNome>
  <IE>111222333</IE>
  <dhEmi>2026-10-01T10:00:00-03:00</dhEmi>
  <vNF>25.00</vNF>
  <tpNF>1</tpNF>
</resNFe>
XML;
    }

    public function test_importa_nfe_completa_com_itens(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('1', 44);

        $nfe = app(NfeImporter::class)->import($unidade, null, $this->xmlNfeCompleta($chave, '123'));

        $this->assertSame($chave, $nfe->chave);
        $this->assertSame('123', $nfe->numero);
        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
        $this->assertSame('99888777000166', $nfe->emitente_cnpj);
        $this->assertCount(1, $nfe->itens);
        $this->assertSame('Caneta esferográfica', $nfe->itens->first()->descricao);
    }

    public function test_mesma_chave_nao_duplica(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('2', 44);
        $xml = $this->xmlNfeCompleta($chave, '456');

        app(NfeImporter::class)->import($unidade, null, $xml);
        app(NfeImporter::class)->import($unidade, null, $xml);

        $this->assertSame(1, Nfe::where('chave', $chave)->count());
        $nfe = Nfe::where('chave', $chave)->first();
        $this->assertCount(1, $nfe->itens);
    }

    public function test_importa_resumo(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('3', 44);

        $nfe = app(NfeImporter::class)->importResumo($unidade, null, $this->xmlResumo($chave));

        $this->assertSame($chave, $nfe->chave);
        $this->assertSame(Nfe::ESTADO_RESUMO, $nfe->estado);
        $this->assertSame('99888777000166', $nfe->emitente_cnpj);
    }

    public function test_resumo_e_promovido_a_completo(): void
    {
        $unidade = $this->unidade();
        $chave = str_repeat('4', 44);

        app(NfeImporter::class)->importResumo($unidade, null, $this->xmlResumo($chave));
        $nfe = app(NfeImporter::class)->import($unidade, null, $this->xmlNfeCompleta($chave, '789'));

        $this->assertSame(Nfe::ESTADO_COMPLETO, $nfe->estado);
        $this->assertSame(1, Nfe::where('chave', $chave)->count());
    }

    public function test_xml_nao_pode_ser_sobrescrito(): void
    {
        Storage::fake('local');

        $unidade = $this->unidade();
        $chave = str_repeat('5', 44);

        $store = app(XmlStore::class);
        $store->put($unidade->tenant, $unidade, $chave, '<xml>original</xml>');

        $this->expectException(RuntimeException::class);
        $store->put($unidade->tenant, $unidade, $chave, '<xml>alterado</xml>');
    }

    public function test_decode_base64_gzip(): void
    {
        $conteudo = '<xml>teste</xml>';
        $base64 = base64_encode(gzencode($conteudo));

        $this->assertSame($conteudo, app(DocZipDecoder::class)->decode($base64));
    }
}
<?php

namespace Tests\Feature\Nfse;

/**
 * XMLs sintéticos no leiaute nacional da NFS-e (estrutura copiada de notas
 * reais do ADN, com dados fictícios) e itens da resposta do ADN.
 */
trait NfseXml
{
    /**
     * Chave de 50 dígitos: município (7) + ambGer (1) + tpInsc (1) + CNPJ do
     * emitente (14) + número (13) + AAMM (4) + código (9) + DV (1).
     */
    protected function chaveNfse(string $prestador = '99888777000166', int $numero = 1, string $aamm = '2310'): string
    {
        return '5300108'.'2'.'2'.$prestador.str_pad((string) $numero, 13, '0', STR_PAD_LEFT).$aamm.'123456789'.'0';
    }

    protected function xmlNfse(string $chave, string $tomador = '11222333000181', string $prestador = '99888777000166', array $extra = []): string
    {
        $vIss = isset($extra['vISSQN']) ? '<vISSQN>'.$extra['vISSQN'].'</vISSQN>' : '';
        $ret = $extra['tpRetISSQN'] ?? '1';
        $interm = isset($extra['interm'])
            ? '<interm><CNPJ>'.$extra['interm'].'</CNPJ><xNome>Intermediário Ltda</xNome></interm>'
            : '';

        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">
  <infNFSe Id="NFS{$chave}">
    <xLocEmi>Brasília</xLocEmi>
    <xLocPrestacao>Brasília</xLocPrestacao>
    <nNFSe>12</nNFSe>
    <cLocIncid>5300108</cLocIncid>
    <xLocIncid>Brasília</xLocIncid>
    <xTribNac>Serralheria.</xTribNac>
    <ambGer>2</ambGer>
    <tpEmis>1</tpEmis>
    <cStat>100</cStat>
    <dhProc>2023-10-20T15:25:29-03:00</dhProc>
    <nDFSe>10866</nDFSe>
    <emit><CNPJ>{$prestador}</CNPJ><xNome>Prestador de Teste Ltda</xNome></emit>
    <valores><vTotalRet>0.00</vTotalRet>{$vIss}<vLiq>4800.00</vLiq></valores>
    <DPS versao="1.01">
      <infDPS Id="DPS1">
        <tpAmb>1</tpAmb>
        <dhEmi>2023-10-20T15:25:29-03:00</dhEmi>
        <serie>900</serie>
        <nDPS>12</nDPS>
        <dCompet>2023-10-20</dCompet>
        <tpEmit>1</tpEmit>
        <cLocEmi>5300108</cLocEmi>
        <prest><CNPJ>{$prestador}</CNPJ></prest>
        <toma><CNPJ>{$tomador}</CNPJ><xNome>Tomador de Teste</xNome></toma>
        {$interm}
        <serv>
          <cServ><cTribNac>141302</cTribNac><xDescServ>Corrimão em aço inox</xDescServ></cServ>
        </serv>
        <valores>
          <vServPrest><vServ>5000.00</vServ></vServPrest>
          <trib><tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>{$ret}</tpRetISSQN></tribMun></trib>
        </valores>
      </infDPS>
    </DPS>
  </infNFSe>
</NFSe>
XML;
    }

    protected function xmlCancelamento(string $chave): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<evento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.00">
  <infEvento Id="EVT{$chave}101101001">
    <ambGer>2</ambGer>
    <nSeqEvento>0</nSeqEvento>
    <dhProc>2023-10-25T21:04:34-03:00</dhProc>
    <pedRegEvento versao="1.00">
      <infPedReg Id="PRE{$chave}101101001">
        <tpAmb>1</tpAmb>
        <dhEvento>2023-10-25T21:04:34-03:00</dhEvento>
        <CNPJAutor>99888777000166</CNPJAutor>
        <chNFSe>{$chave}</chNFSe>
        <nPedRegEvento>1</nPedRegEvento>
        <e101101>
          <xDesc>Cancelamento de NFS-e</xDesc>
          <cMotivo>1</cMotivo>
          <xMotivo>erro no preenchimento do tomador</xMotivo>
        </e101101>
      </infPedReg>
    </pedRegEvento>
  </infEvento>
</evento>
XML;
    }

    /**
     * Item do `LoteDFe`, como o ADN entrega (XML em GZip + Base64).
     *
     * @return array<string, mixed>
     */
    protected function itemLote(int $nsu, string $chave, string $xml, string $tipo = 'NFSE', ?string $tipoEvento = null): array
    {
        return array_filter([
            'NSU' => $nsu,
            'ChaveAcesso' => $chave,
            'TipoDocumento' => $tipo,
            'TipoEvento' => $tipoEvento,
            'ArquivoXml' => base64_encode(gzencode($xml)),
            'DataHoraGeracao' => '2023-10-20T15:25:29.123',
        ], fn ($v) => $v !== null);
    }

    /**
     * Corpo JSON de uma resposta do ADN.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @param  array<int, array{Codigo:string, Descricao:string}>  $erros
     */
    protected function corpoAdn(array $itens, string $status = 'DOCUMENTOS_LOCALIZADOS', array $erros = []): string
    {
        return json_encode([
            'TipoConsulta' => 'CnpjBase',
            'StatusProcessamento' => $status,
            'LoteDFe' => $itens,
            'Alertas' => [],
            'Erros' => $erros,
            'TipoAmbiente' => 'PRODUCAO',
            'VersaoAplicativo' => '1.0.0.0',
            'DataHoraProcessamento' => '2026-11-07T15:45:21-03:00',
        ]);
    }

    protected function corpoFilaVazia(): string
    {
        return $this->corpoAdn([], 'NENHUM_DOCUMENTO_LOCALIZADO', [[
            'Codigo' => 'E2220',
            'Descricao' => 'Nenhum documento localizado - não existem documentos fiscais para o Contribuinte a partir do NSU informado.',
        ]]);
    }
}

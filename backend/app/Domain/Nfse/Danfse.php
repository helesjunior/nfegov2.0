<?php

namespace App\Domain\Nfse;

use Com\Tecnick\Barcode\Barcode;
use DOMDocument;
use DOMXPath;
use NFePHP\DA\Legacy\Pdf;
use RuntimeException;

/**
 * DANFSe (Documento Auxiliar da NFS-e), gerado localmente a partir do XML.
 *
 * Segue a Nota Técnica SE/CGNFS-e nº 008 v1.02 (14/07/2026), que passou o
 * DANFSe para os sistemas: a API oficial que o gerava foi suspensa.
 *
 * O que está seguindo a NT: A4 retrato em uma única página; margens de 2 mm e
 * borda de 1 pt; linhas de 0,5 pt; sombreamento cinza claro no cabeçalho, nos
 * títulos dos blocos, no "Emitente da NFS-e" e no "Valor Líquido da NFS-e +
 * IBS/CBS"; títulos de bloco 7 pt negrito caixa alta; rótulos 6 pt negrito;
 * conteúdo 7 pt; "DANFSe v2.0" e "Documento Auxiliar da NFS-e" em 9 pt negrito;
 * "NFS-e SEM VALIDADE JURÍDICA" em vermelho nas notas de homologação; QR Code
 * com a URL de consulta pública em X 17,48 cm e Y 1,67 cm; marca d'água
 * diagonal CANCELADA/SUBSTITUÍDA a 50 pt em cinza; campos sem dado com "-";
 * só se imprime o que consta no XML.
 *
 * Diferenças conhecidas: a NT pede Arial e Microsoft Sans Serif, e aqui as
 * duas saem na Helvetica embutida do PDF (equivalente à Arial); a posição de
 * cada campo segue a ordem e os tamanhos da NT, mas não foi comparada com o
 * desenho do Anexo I; o canhoto (opcional) não é impresso; o bloco de IBS/CBS
 * só sai quando a nota o traz.
 */
class Danfse
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';

    private const URL_CONSULTA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    private const X = 3.0;       // margem esquerda do conteúdo (mm)
    private const W = 204.0;     // largura do conteúdo (20,40 cm)
    private const LINHA = 6.3;   // altura de uma linha de campos (0,63 cm)
    private const TITULO = 3.8;  // altura do título de bloco (0,38 cm)
    private const FIM = 294.0;   // fim da área útil (mm)

    private Pdf $pdf;

    private DOMXPath $xp;

    private float $y = 0;

    /**
     * @param  string  $situacao  `normal`, `cancelada` ou `substituida`
     * @param  bool  $comprimir  `false` deixa o conteúdo legível (usado nos testes)
     */
    public function render(string $xml, string $situacao = 'normal', bool $comprimir = true): string
    {
        $dom = new DOMDocument;

        if (@$dom->loadXML($xml) === false || $dom->documentElement?->localName !== 'NFSe') {
            throw new RuntimeException('XML de NFS-e inválido para gerar o DANFSe.');
        }

        $this->xp = new DOMXPath($dom);
        $this->xp->registerNamespace('n', self::NS);

        $chave = preg_replace('/\D/', '', preg_replace('/^NFS/', '', $this->v('//n:infNFSe/@Id')));

        if (strlen($chave) !== 50) {
            throw new RuntimeException('XML de NFS-e sem chave de acesso válida.');
        }

        $this->pdf = new Pdf('P', 'mm', 'A4');
        $this->pdf->setCompression($comprimir);
        $this->pdf->setMargins(0, 0, 0);
        $this->pdf->setAutoPageBreak(false);
        $this->pdf->setTitle('DANFSe '.$chave);
        $this->pdf->addPage();

        // Borda da página (1 pt) a 2 mm da borda do papel.
        $this->pdf->setLineWidth(0.3528);
        $this->pdf->setDrawColor(0);
        $this->pdf->rect(2, 2, 206, 293);
        $this->pdf->setLineWidth(0.1764);

        $this->y = 3.0;
        $this->cabecalhoEIdentificacao($chave, $situacao);
        $this->prestador();
        $this->parte('TOMADOR / ADQUIRENTE DA OPERAÇÃO', '//n:infDPS/n:toma', 'TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
        $this->destinatario();
        $this->parte('INTERMEDIÁRIO DA OPERAÇÃO', '//n:infDPS/n:interm', 'INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', true);
        $this->servico();
        $this->issqn();
        $this->tributacaoFederal();
        $this->ibsCbs();
        $this->valorTotal();
        $this->informacoesComplementares();

        if ($situacao === 'cancelada' || $situacao === 'substituida') {
            $this->marcaDagua($situacao === 'cancelada' ? 'CANCELADA' : 'SUBSTITUÍDA');
        }

        return (string) $this->pdf->output('', 'S');
    }

    // ---------------------------------------------------------------- blocos

    private function cabecalhoEIdentificacao(string $chave, string $situacao): void
    {
        $homologacao = $this->v('//n:infDPS/n:tpAmb') === '2';

        // Cabeçalho (1,16 cm), sombreado.
        $this->caixa(self::X, $this->y, self::W, 11.6, true);

        $logo = resource_path('danfse/logo-nfse.png');
        if (is_file($logo)) {
            $this->pdf->image($logo, self::X + 3, $this->y + 2.2, 40, 0, 'PNG');
        }

        $this->texto('Arial', 'B', 9, self::X + 54, $this->y + 1.2, 100, 4, 'DANFSe v2.0', 'C');
        $this->texto('Arial', 'B', 9, self::X + 54, $this->y + 5.2, 100, 4, 'Documento Auxiliar da NFS-e', 'C');

        if ($homologacao) {
            $this->pdf->setTextColor(255, 0, 0);
            $this->texto('Arial', 'B', 9, self::X + 54, $this->y + 9.0, 100, 3, 'NFS-e SEM VALIDADE JURÍDICA', 'C');
            $this->pdf->setTextColor(0);
        }

        $municipio = trim($this->v('//n:infNFSe/n:xLocEmi'));
        $uf = $this->v('//n:infNFSe/n:emit/n:enderNac/n:UF');
        $this->texto('Arial', '', 8, self::X + 155, $this->y + 1.0, 48, 3.5, $this->aparar(($municipio !== '' ? $municipio : '-').($uf !== '' ? ' / '.$uf : ''), 30), 'R');
        $this->texto('Arial', '', 6, self::X + 155, $this->y + 5.0, 48, 3, 'Ambiente gerador: '.$this->ambienteGerador(), 'R');
        $this->texto('Arial', '', 6, self::X + 155, $this->y + 8.0, 48, 3, 'Ambiente: '.($homologacao ? 'Produção restrita' : 'Produção'), 'R');

        $this->y += 11.6;
        $topo = $this->y;

        // Identificação (campos à esquerda, QR Code à direita).
        $larg = 51.0;
        $h = 6.7;
        $campos = [
            [['Chave de Acesso da NFS-e', $chave, 3 * $larg]],
            [
                ['Número da NFS-e', $this->vazio($this->v('//n:infNFSe/n:nNFSe')), $larg],
                ['Competência da NFS-e', $this->data($this->v('//n:infDPS/n:dCompet')), $larg],
                ['Data e Hora da Emissão da NFS-e', $this->dataHora($this->v('//n:infNFSe/n:dhProc')), $larg],
            ],
            [
                ['Número da DPS', $this->vazio($this->v('//n:infDPS/n:nDPS')), $larg],
                ['Série da DPS', $this->vazio($this->v('//n:infDPS/n:serie')), $larg],
                ['Data e Hora da Emissão da DPS', $this->dataHora($this->v('//n:infDPS/n:dhEmi')), $larg],
            ],
            [
                ['Emitente da NFS-e', $this->tpEmit(), $larg, true],
                ['Situação da NFS-e', $this->rotuloSituacao($situacao), $larg],
                ['Finalidade', $this->finalidade(), $larg],
            ],
        ];

        foreach ($campos as $linha) {
            $this->linhaCampos($linha, $h, true);
        }

        // QR Code com a URL de consulta pública (X 17,48 cm / Y 1,67 cm).
        $barcode = (new Barcode)->getBarcodeObj('QRCODE,M', self::URL_CONSULTA.$chave, -4, -4, 'black', [-2, -2, -2, -2])
            ->setBackgroundColor('white');
        $png = 'data://text/plain;base64,'.base64_encode($barcode->getPngData());
        $this->pdf->image($png, 174.8, 16.7, 16, 16, 'PNG');

        $this->setFonte('Arial', '', 6);
        $this->pdf->setXY(157.5, 33.2);
        $this->pdf->multiCell(
            48,
            2.3,
            $this->c("A autenticidade desta NFS-e pode ser verificada pela leitura deste código QR ou pela consulta da chave de acesso no portal nacional da NFS-e"),
            0,
            'C',
        );

        $this->y = max($this->y, $topo + 4 * $h);
        $this->caixa(self::X + 3 * $larg, $topo, $larg, 4 * $h, false);
        // O QR e o texto foram desenhados antes; redesenha só a moldura.
    }

    private function prestador(): void
    {
        $p = '//n:infDPS/n:prest';
        $e = '//n:infNFSe/n:emit';

        $this->titulo('PRESTADOR / FORNECEDOR');

        $doc = $this->documento($p) ?: $this->documento($e);
        $this->linhaCampos([
            ['CNPJ / CPF / NIF', $this->vazio($this->formatarDoc($doc)), 51],
            ['Inscrição Municipal', $this->vazio($this->v($p.'/n:IM')), 51],
            ['Telefone', $this->vazio($this->fone($this->v($p.'/n:fone') ?: $this->v($e.'/n:fone'))), 51],
            ['E-mail', $this->vazio($this->aparar($this->v($p.'/n:email') ?: $this->v($e.'/n:email'), 38)), 51],
        ]);

        $end = $e.'/n:enderNac';
        $this->linhaCampos([
            ['Nome / Nome Empresarial', $this->vazio($this->aparar($this->v($e.'/n:xNome') ?: $this->v($p.'/n:xNome'), 77)), 102],
            ['Município / UF', $this->vazio($this->municipioUf($this->v('//n:infNFSe/n:xLocEmi'), $this->v($end.'/n:UF'))), 51],
            ['Código IBGE / CEP', $this->vazio($this->ibgeCep($this->v($end.'/n:cMun'), $this->v($end.'/n:CEP'))), 51],
        ]);
        $this->linhaCampos([
            ['Endereço', $this->vazio($this->aparar($this->endereco($end), 110)), 204],
        ]);

        $this->linhaCampos([
            ['Simples Nacional na Data de Competência', $this->vazio($this->aparar($this->opSimples($this->v($p.'/n:regTrib/n:opSimpNac')), 60)), 102],
            ['Regime de Apuração Tributária pelo SN', $this->vazio($this->aparar($this->regimeApuracao($this->v($p.'/n:regTrib/n:regApTribSN')), 75)), 102],
        ]);
    }

    /**
     * Tomador e intermediário: ou os campos, ou a frase prevista na NT 2.3.1.
     */
    private function parte(string $titulo, string $base, string $naoIdentificado, bool $inscricao = true): void
    {
        $this->titulo($titulo);

        if ($this->xp->query($base)->length === 0) {
            $this->linhaCampos([[$naoIdentificado, '', 204, false, true]], 4.2);

            return;
        }

        $this->camposParte($base);
    }

    private function destinatario(): void
    {
        $base = '//n:infDPS/n:IBSCBS/n:dest';
        $this->titulo('DESTINATÁRIO DA OPERAÇÃO');

        if ($this->xp->query($base)->length === 0) {
            $this->linhaCampos([['DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', '', 204, false, true]], 4.2);

            return;
        }

        $this->camposParte($base);
    }

    private function camposParte(string $base): void
    {
        $end = $base.'/n:end/n:endNac';
        $this->linhaCampos([
            ['CNPJ / CPF / NIF', $this->vazio($this->formatarDoc($this->documento($base))), 51],
            ['Inscrição Municipal', $this->vazio($this->v($base.'/n:IM')), 51],
            ['Telefone', $this->vazio($this->fone($this->v($base.'/n:fone'))), 51],
            ['E-mail', $this->vazio($this->aparar($this->v($base.'/n:email'), 38)), 51],
        ]);
        $this->linhaCampos([
            ['Nome / Nome Empresarial', $this->vazio($this->aparar($this->v($base.'/n:xNome'), 77)), 102],
            ['Município / UF', $this->vazio($this->municipioDaParte($base)), 51],
            ['Código IBGE / CEP', $this->vazio($this->ibgeCep($this->v($end.'/n:cMun'), $this->v($end.'/n:CEP'))), 51],
        ]);
        $this->linhaCampos([
            ['Endereço', $this->vazio($this->aparar($this->endereco($base.'/n:end'), 110)), 204],
        ]);
    }

    private function servico(): void
    {
        $s = '//n:infDPS/n:serv';

        $this->titulo('SERVIÇO PRESTADO');

        $this->linhaCampos([
            ['Código de Tributação Nacional', $this->vazio($this->mascara($this->v($s.'/n:cServ/n:cTribNac'), 'nn.nn.nn')), 51],
            ['Código de Tributação Municipal', $this->vazio($this->v($s.'/n:cServ/n:cTribMun')), 51],
            ['Código da NBS', $this->vazio($this->mascara($this->v($s.'/n:cServ/n:cNBS'), 'n.nnnn.nn.nn')), 51],
            ['Local da Prestação', $this->vazio($this->aparar($this->v('//n:infNFSe/n:xLocPrestacao') ?: $this->v($s.'/n:locPrest/n:cLocPrestacao'), 30)), 51],
        ]);

        $descCodigo = $this->v('//n:infNFSe/n:xTribMun') ?: $this->v('//n:infNFSe/n:xTribNac');
        $this->linhaCampos([
            ['Descrição do Código de Tributação Nacional / Municipal', $this->vazio($this->aparar($descCodigo, 167)), 204],
        ]);

        // Descrição do serviço: altura variável (a NT dá o espaço que sobra).
        $this->quadroTexto('', $this->vazio($this->truncar($this->v($s.'/n:cServ/n:xDescServ'), 1297)), 34.0);
    }

    private function issqn(): void
    {
        $t = '//n:infDPS/n:valores/n:trib/n:tribMun';
        $tributo = $this->v($t.'/n:tribISSQN');

        if ($tributo === '' || $tributo === '3' || $tributo === '4') {
            $this->titulo('TRIBUTAÇÃO MUNICIPAL (ISSQN)');
            $this->linhaCampos([['TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN', '', 204, false, true]], 4.2);

            return;
        }

        $this->titulo('TRIBUTAÇÃO MUNICIPAL (ISSQN)');

        $incidencia = $this->v('//n:infNFSe/n:xLocIncid');
        $this->linhaCampos([
            ['Tipo de Tributação do ISSQN', $this->vazio($this->tipoTributacao($tributo)), 51],
            ['Município de Incidência do ISSQN', $this->vazio($this->aparar($incidencia, 30)), 51],
            ['Regime Especial de Tributação do ISSQN', $this->vazio($this->regimeEspecial($this->v('//n:infDPS/n:prest/n:regTrib/n:regEspTrib'))), 51],
            ['Tipo de Imunidade do ISSQN', $this->vazio($this->aparar($this->imunidade($this->v($t.'/n:tpImunidade')), 37)), 51],
        ]);

        $linha2 = [
            $this->v($t.'/n:exigSusp/n:tpSusp'),
            $this->v($t.'/n:exigSusp/n:nProcesso'),
            $this->v($t.'/n:BM/n:nBM'),
            $this->v('//n:infNFSe/n:valores/n:vCalcBM'),
        ];

        // Nota 5: a linha pode sair quando nenhum dos campos tem dado no XML.
        if (array_filter($linha2) !== []) {
            $this->linhaCampos([
                ['Suspensão da Exigibilidade do ISSQN', $this->vazio($this->suspensao($linha2[0])), 51],
                ['Número Processo Suspensão', $this->vazio($linha2[1]), 51],
                ['Benefício Municipal', $this->vazio($linha2[2]), 51],
                ['Cálculo do BM', $this->vazio($this->moeda($linha2[3])), 51],
            ]);
        }

        $this->linhaCampos([
            ['Total Deduções/Reduções', $this->vazio($this->moeda($this->v('//n:infDPS/n:valores/n:vDedRed//n:vDR'))), 51],
            ['Desconto Incondicionado', $this->vazio($this->moeda($this->v('//n:infDPS/n:valores/n:vDescCondIncond/n:vDescIncond'))), 51],
            ['BC ISSQN', $this->vazio($this->moeda($this->v('//n:infNFSe/n:valores/n:vBC'))), 51],
            ['Alíquota Aplicada', $this->vazio($this->percentual($this->v('//n:infNFSe/n:valores/n:pAliqAplic'))), 51],
        ]);

        $this->linhaCampos([
            ['Retenção do ISSQN', $this->vazio($this->retencao($this->v($t.'/n:tpRetISSQN'))), 102],
            ['ISSQN Apurado', $this->vazio($this->moeda($this->v('//n:infNFSe/n:valores/n:vISSQN'))), 102],
        ]);
    }

    private function tributacaoFederal(): void
    {
        $f = '//n:infDPS/n:valores/n:trib/n:tribFed';

        $this->titulo('TRIBUTAÇÃO FEDERAL (EXCETO CBS)');
        $this->linhaCampos([
            ['IRRF', $this->vazio($this->moeda($this->v($f.'/n:vRetIRRF'))), 40.8],
            ['Contribuição Previdenciária - Retida', $this->vazio($this->moeda($this->v($f.'/n:vRetCP'))), 40.8],
            ['Contribuições Sociais - Retidas', $this->vazio($this->moeda($this->v($f.'/n:vRetCSLL'))), 40.8],
            ['PIS - Débito Apuração Própria', $this->vazio($this->moeda($this->v($f.'/n:piscofins/n:vPis'))), 40.8],
            ['COFINS - Débito Apuração Própria', $this->vazio($this->moeda($this->v($f.'/n:piscofins/n:vCofins'))), 40.8],
        ]);
    }

    /**
     * Bloco do IBS/CBS: só sai quando a NFS-e traz o grupo.
     */
    private function ibsCbs(): void
    {
        $g = '//n:infNFSe/n:IBSCBS';
        $d = '//n:infDPS/n:IBSCBS';

        if ($this->xp->query($g)->length === 0 && $this->xp->query($d)->length === 0) {
            return;
        }

        $cst = $this->v($d.'/n:valores/n:trib/n:gIBSCBS/n:CST');
        $cclass = $this->v($d.'/n:valores/n:trib/n:gIBSCBS/n:cClassTrib');
        $tot = $g.'/n:totCIBS';

        $this->titulo('TRIBUTAÇÃO IBS / CBS');
        $this->linhaCampos([
            ['CST / cClassTrib', $this->vazio(trim($cst.' / '.$cclass, ' /')), 68],
            ['Base de Cálculo Após Exclusões e Reduções', $this->vazio($this->moeda($this->v($g.'/n:valores/n:vBC'))), 68],
            ['Alíquota da CBS', $this->vazio($this->percentual($this->v($g.'/n:valores/n:fed/n:pCBS'))), 68],
        ]);
        $this->linhaCampos([
            ['Valor Total Apurado do IBS', $this->vazio($this->moeda($this->v($tot.'/n:gIBS/n:vIBSTot'))), 102],
            ['Valor Total Apurado da CBS', $this->vazio($this->moeda($this->v($tot.'/n:gCBS/n:vCBS'))), 102],
        ]);
    }

    private function valorTotal(): void
    {
        $v = '//n:infDPS/n:valores';
        $ibs = $this->v('//n:infNFSe/n:IBSCBS/n:totCIBS/n:gIBS/n:vIBSTot');
        $cbs = $this->v('//n:infNFSe/n:IBSCBS/n:totCIBS/n:gCBS/n:vCBS');
        $totalIbsCbs = ($ibs !== '' || $cbs !== '') ? (float) $ibs + (float) $cbs : null;

        $this->titulo('VALOR TOTAL DA NFS-E');
        $this->linhaCampos([
            ['Valor da Operação / Serviço', $this->vazio($this->moeda($this->v($v.'/n:vServPrest/n:vServ'))), 51],
            ['Desconto Incondicionado', $this->vazio($this->moeda($this->v($v.'/n:vDescCondIncond/n:vDescIncond'))), 51],
            ['Desconto Condicionado', $this->vazio($this->moeda($this->v($v.'/n:vDescCondIncond/n:vDescCond'))), 51],
            ['Total das Retenções (ISSQN / Federais)', $this->vazio($this->moeda($this->v('//n:infNFSe/n:valores/n:vTotalRet'))), 51],
        ]);
        $this->linhaCampos([
            ['Valor Líquido da NFS-e', $this->vazio($this->moeda($this->v('//n:infNFSe/n:valores/n:vLiq'))), 68],
            ['Total do IBS/CBS', $totalIbsCbs === null ? '-' : $this->moeda((string) $totalIbsCbs), 68],
            ['Valor Líquido da NFS-e + IBS/CBS', $this->vazio($this->moeda($this->v('//n:infNFSe/n:valores/n:vTotNF') ?: $this->v('//n:infNFSe/n:valores/n:vLiq'))), 68, true],
        ]);
    }

    private function informacoesComplementares(): void
    {
        $s = '//n:infDPS/n:serv';
        $partes = [];

        $mapa = [
            'Inf. Cont.: ' => $this->v($s.'/n:infoCompl/n:xInfComp'),
            'NFS-e Subst.: ' => $this->v('//n:infDPS/n:subst/n:chSubstda'),
            'Doc. Ref.: ' => $this->v($s.'/n:infoCompl/n:docRef'),
            'Cod. Obra: ' => $this->v($s.'/n:obra/n:cObra'),
            'Insc. Imob.: ' => $this->v($s.'/n:obra/n:inscImobFisc'),
            'Cod. Evt.: ' => $this->v($s.'/n:atvEvento/n:idAtvEvt'),
            'Doc. Tec.: ' => $this->v($s.'/n:infoCompl/n:idDocTec'),
            'Núm. Ped.: ' => $this->v($s.'/n:infoCompl/n:xPed'),
            'Item Ped.: ' => $this->v($s.'/n:infoCompl/n:gItemPed/n:xItemPed'),
            'Inf. A. T. Mun.: ' => $this->v('//n:infNFSe/n:xOutInf'),
        ];

        foreach ($mapa as $rotulo => $valor) {
            if ($valor !== '') {
                $partes[] = $rotulo.$valor;
            }
        }

        $partes[] = $this->totaisAproximados();

        $this->titulo('INFORMAÇÕES COMPLEMENTARES');
        $altura = max(14.0, self::FIM - $this->y);
        $this->quadroTexto('', $this->truncar(implode(' | ', $partes), 1997), $altura);
    }

    /**
     * Linha obrigatória (nota 10 da NT): totais aproximados dos tributos.
     */
    private function totaisAproximados(): string
    {
        $t = '//n:infDPS/n:valores/n:trib/n:totTrib';

        $valores = [
            'Federais' => [$this->v($t.'/n:vTotTrib/n:vTotTribFed'), $this->v($t.'/n:pTotTrib/n:pTotTribFed')],
            'Estaduais' => [$this->v($t.'/n:vTotTrib/n:vTotTribEst'), $this->v($t.'/n:pTotTrib/n:pTotTribEst')],
            'Municipais' => [$this->v($t.'/n:vTotTrib/n:vTotTribMun'), $this->v($t.'/n:pTotTrib/n:pTotTribMun')],
        ];

        $itens = [];
        foreach ($valores as $nome => [$real, $pct]) {
            $itens[] = $nome.': '.($real !== '' ? $this->moeda($real) : ($pct !== '' ? $this->percentual($pct) : '-'));
        }

        return 'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '.implode('; ', $itens);
    }

    private function marcaDagua(string $texto): void
    {
        $this->pdf->setTextColor(166); // cinza K35
        $this->setFonte('Arial', '', 60);
        $this->pdf->rotate(45, 105, 160);
        $largura = $this->pdf->getStringWidth($this->c($texto));
        $this->pdf->text(105 - $largura / 2, 160, $this->c($texto));
        $this->pdf->rotate(0);
        $this->pdf->setTextColor(0);
    }

    // ------------------------------------------------------------- primitivas

    /**
     * @param  array<int, array{0:string,1:string,2:float|int,3?:bool,4?:bool}>  $campos  [rótulo, valor, largura, sombreado, frase]
     */
    private function linhaCampos(array $campos, float $altura = self::LINHA, bool $rotuloAlto = false): void
    {
        $x = self::X;

        foreach ($campos as $campo) {
            [$rotulo, $valor, $largura] = $campo;
            $sombra = $campo[3] ?? false;
            $frase = $campo[4] ?? false;

            $this->caixa($x, $this->y, (float) $largura, $altura, $sombra);

            if ($frase) {
                $this->texto('Arial', 'B', 7, $x, $this->y + 0.8, (float) $largura, 3, $rotulo, 'C');
            } else {
                $this->texto('Arial', 'B', $rotuloAlto ? 7 : 6, $x + 0.8, $this->y + 0.5, (float) $largura - 1.6, 2.4, $rotulo, 'L', $rotuloAlto);
                $this->texto('Arial', '', 7, $x + 0.8, $this->y + 3.1, (float) $largura - 1.6, 3, $this->ajustar($valor, (float) $largura - 1.6), 'L');
            }

            $x += $largura;
        }

        $this->y += $altura;
    }

    private function quadroTexto(string $rotulo, string $texto, float $altura): void
    {
        $this->caixa(self::X, $this->y, self::W, $altura, false);
        $this->setFonte('Arial', '', 7);
        $this->pdf->setXY(self::X + 0.8, $this->y + 0.8);
        $this->pdf->multiCell(self::W - 1.6, 3.0, $this->c($texto), 0, 'L');
        $this->y += $altura;
    }

    private function titulo(string $texto): void
    {
        $this->caixa(self::X, $this->y, self::W, self::TITULO, true);
        $this->texto('Arial', 'B', 7, self::X + 0.8, $this->y + 0.6, self::W - 1.6, 3, $texto, 'L');
        $this->y += self::TITULO;
    }

    /**
     * Retângulo com linha de 0,5 pt e, opcionalmente, fundo cinza claro (5%).
     */
    private function caixa(float $x, float $y, float $w, float $h, bool $sombra): void
    {
        $this->pdf->setLineWidth(0.1764);
        $this->pdf->setDrawColor(0);

        if ($sombra) {
            $this->pdf->setFillColor(242);
            $this->pdf->rect($x, $y, $w, $h, 'DF');
            $this->pdf->setFillColor(255);

            return;
        }

        $this->pdf->rect($x, $y, $w, $h);
    }

    private function texto(string $fonte, string $estilo, float $tamanho, float $x, float $y, float $w, float $h, string $texto, string $alinhamento = 'L', bool $caixaAlta = false): void
    {
        $this->setFonte($fonte, $estilo, $tamanho);
        $this->pdf->setXY($x, $y);
        $this->pdf->cell($w, $h, $this->c($caixaAlta ? mb_strtoupper($texto) : $texto), 0, 0, $alinhamento);
    }

    private function setFonte(string $fonte, string $estilo, float $tamanho): void
    {
        $this->pdf->setFont($fonte, $estilo, $tamanho);
    }

    /**
     * Texto em ISO-8859-1/CP1252, que é o que as fontes embutidas do PDF usam.
     */
    private function c(string $texto): string
    {
        return mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
    }

    /**
     * Corta o texto com reticências quando passa de `$largura` mm (7 pt).
     */
    private function ajustar(string $texto, float $largura): string
    {
        $this->setFonte('Arial', '', 7);

        if ($this->pdf->getStringWidth($this->c($texto)) <= $largura) {
            return $texto;
        }

        while ($texto !== '' && $this->pdf->getStringWidth($this->c($texto.'...')) > $largura) {
            $texto = mb_substr($texto, 0, -1);
        }

        return $texto.'...';
    }

    // ---------------------------------------------------------------- leitura

    private function v(string $consulta): string
    {
        return trim((string) $this->xp->query($consulta)->item(0)?->nodeValue);
    }

    private function vazio(string $valor): string
    {
        return $valor === '' ? '-' : $valor;
    }

    private function aparar(string $texto, int $max): string
    {
        $texto = preg_replace('/\s+/', ' ', trim($texto));

        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 3).'...' : $texto;
    }

    /**
     * Como `aparar`, mas preserva as quebras de linha (descrição do serviço).
     */
    private function truncar(string $texto, int $max): string
    {
        $texto = trim(preg_replace("/[ 	]+/", ' ', str_replace("
", "
", $texto)));

        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 3).'...' : $texto;
    }

    /**
     * Nome do município de uma parte (tomador, destinatário, intermediário).
     * O XML só traz o código IBGE: o nome só sai quando a própria nota o dá
     * para o mesmo código (emissão, prestação ou incidência); senão, o código.
     */
    private function municipioDaParte(string $base): string
    {
        $exterior = $this->v($base.'/n:end/n:endExt/n:xCidade');
        if ($exterior !== '') {
            return $exterior;
        }

        $codigo = $this->v($base.'/n:end/n:endNac/n:cMun');
        $conhecidos = [
            $this->v('//n:infNFSe/n:cLocIncid') => $this->v('//n:infNFSe/n:xLocIncid'),
            $this->v('//n:infDPS/n:cLocEmi') => $this->v('//n:infNFSe/n:xLocEmi'),
            $this->v('//n:infDPS/n:serv/n:locPrest/n:cLocPrestacao') => $this->v('//n:infNFSe/n:xLocPrestacao'),
        ];

        return $codigo !== '' && ($conhecidos[$codigo] ?? '') !== '' ? $conhecidos[$codigo] : $codigo;
    }

    private function documento(string $base): string
    {
        foreach (['CNPJ', 'CPF', 'NIF'] as $tag) {
            $valor = $this->v($base.'/n:'.$tag);
            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    private function formatarDoc(string $doc): string
    {
        $d = preg_replace('/\D/', '', $doc);

        return match (strlen($d)) {
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d),
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d),
            default => $doc,
        };
    }

    private function fone(string $fone): string
    {
        $d = preg_replace('/\D/', '', $fone);

        return match (strlen($d)) {
            11 => preg_replace('/^(\d{2})(\d{5})(\d{4})$/', '($1) $2-$3', $d),
            10 => preg_replace('/^(\d{2})(\d{4})(\d{4})$/', '($1) $2-$3', $d),
            default => $fone,
        };
    }

    private function endereco(string $base): string
    {
        $nac = $base.'/n:endNac';
        $ext = $base.'/n:endExt';
        $campos = [
            $this->v($base.'/n:xLgr') ?: $this->v($nac.'/n:xLgr') ?: $this->v($ext.'/n:xLgr'),
            $this->v($base.'/n:nro') ?: $this->v($nac.'/n:nro') ?: $this->v($ext.'/n:nro'),
            $this->v($base.'/n:xCpl') ?: $this->v($nac.'/n:xCpl') ?: $this->v($ext.'/n:xCpl'),
            $this->v($base.'/n:xBairro') ?: $this->v($nac.'/n:xBairro') ?: $this->v($ext.'/n:xBairro'),
        ];

        return implode(', ', array_filter($campos, fn ($c) => $c !== ''));
    }

    private function municipioUf(string $municipio, string $uf): string
    {
        return $municipio === '' ? '' : $municipio.($uf !== '' ? ' / '.$uf : '');
    }

    private function ibgeCep(string $ibge, string $cep): string
    {
        $cep = preg_replace('/^(\d{2})(\d{3})(\d{3})$/', '$1.$2-$3', preg_replace('/\D/', '', $cep));

        return implode(' / ', array_filter([$ibge, $cep], fn ($c) => $c !== ''));
    }

    private function data(string $iso): string
    {
        $t = strtotime($iso);

        return $t ? date('d/m/Y', $t) : '-';
    }

    private function dataHora(string $iso): string
    {
        if ($iso === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable($iso))->format('d/m/Y H:i:s');
        } catch (\Throwable) {
            return '-';
        }
    }

    private function moeda(string $valor): string
    {
        return $valor === '' || ! is_numeric($valor) ? '' : 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    private function percentual(string $valor): string
    {
        return $valor === '' || ! is_numeric($valor) ? '' : number_format((float) $valor, 2, ',', '.').'%';
    }

    private function mascara(string $valor, string $mascara): string
    {
        $d = preg_replace('/\D/', '', $valor);

        if ($d === '' || strlen($d) !== substr_count($mascara, 'n')) {
            return $valor;
        }

        $i = 0;

        return preg_replace_callback('/n/', function () use ($d, &$i) {
            return $d[$i++];
        }, $mascara);
    }

    // ------------------------------------------------------------ descrições

    private function ambienteGerador(): string
    {
        return match ($this->v('//n:infNFSe/n:ambGer')) {
            '1' => 'Prefeitura',
            '2' => 'Sistema Nacional NFS-e',
            default => '-',
        };
    }

    private function tpEmit(): string
    {
        return match ($this->v('//n:infDPS/n:tpEmit')) {
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
            default => '-',
        };
    }

    private function rotuloSituacao(string $situacao): string
    {
        return match ($situacao) {
            'cancelada' => 'Cancelada',
            'substituida' => 'Substituída',
            default => 'Normal',
        };
    }

    private function finalidade(): string
    {
        $fin = $this->v('//n:infDPS/n:IBSCBS/n:finNFSe');

        return match ($fin) {
            '', '0' => 'NFS-e regular',
            default => 'Finalidade '.$fin,
        };
    }

    private function opSimples(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Não Optante',
            '2' => 'Optante - Microempreendedor Individual (MEI)',
            '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte (ME/EPP)',
            default => '',
        };
    }

    private function regimeApuracao(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
            '2' => 'Regime de apuração dos tributos federais pelo SN e o ISSQN pela NFS-e conforme respectivas legislações municipais',
            '3' => 'Regime de apuração dos tributos federais e municipal pela NFS-e conforme respectivas legislações federal e municipal',
            default => '',
        };
    }

    private function tipoTributacao(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Operação Tributável',
            '2' => 'Imunidade',
            '3' => 'Exportação de Serviço',
            '4' => 'Não Incidência',
            default => '',
        };
    }

    private function regimeEspecial(string $codigo): string
    {
        return match ($codigo) {
            '0' => 'Nenhum',
            '1' => 'Ato Cooperado (Cooperativa)',
            '2' => 'Estimativa',
            '3' => 'Microempresa Municipal',
            '4' => 'Notário ou Registrador',
            '5' => 'Profissional Autônomo',
            '6' => 'Sociedade de Profissionais',
            default => '',
        };
    }

    private function imunidade(string $codigo): string
    {
        return match ($codigo) {
            '0' => 'Imunidade (tipo não informado na NF-e)',
            '1' => 'Patrimônio, renda ou serviços, uns dos outros',
            '2' => 'Entidades religiosas e templos de qualquer culto',
            '3' => 'Partidos políticos, entidades sindicais e instituições de educação e assistência social',
            '4' => 'Livros, jornais, periódicos e o papel destinado a sua impressão',
            '5' => 'Fonogramas e videofonogramas musicais produzidos no Brasil',
            default => '',
        };
    }

    private function suspensao(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Exigibilidade Suspensa por Decisão Judicial',
            '2' => 'Exigibilidade Suspensa por Processo Administrativo',
            default => '',
        };
    }

    private function retencao(string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Não Retido',
            '2' => 'Retido pelo Tomador',
            '3' => 'Retido pelo Intermediário',
            default => '',
        };
    }
}

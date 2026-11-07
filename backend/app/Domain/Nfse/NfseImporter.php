<?php

namespace App\Domain\Nfse;

use App\Domain\Nfe\XmlStore;
use App\Models\Nfse;
use App\Models\NfseEvento;
use App\Models\Unidade;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Importa os documentos da fila do ADN (NFS-e e eventos) para a unidade.
 *
 * A identidade da nota é (unidade, chave), como na NF-e. Reprocessar o mesmo
 * documento não duplica nada: o XML é gravado de forma idempotente e o
 * registro usa `updateOrCreate`.
 *
 * Leiaute: `NFSe/infNFSe` (namespace `http://www.sped.fazenda.gov.br/nfse`),
 * versões 1.00 e 1.01. O evento tem raiz `evento/infEvento/pedRegEvento`.
 */
class NfseImporter
{
    private const NS = 'http://www.sped.fazenda.gov.br/nfse';

    /**
     * TipoEvento do ADN que muda a situação da nota.
     */
    private const SITUACAO_POR_EVENTO = [
        'CANCELAMENTO' => Nfse::SITUACAO_CANCELADA,
        'CANCELAMENTO_DEFERIDO_ANALISE_FISCAL' => Nfse::SITUACAO_CANCELADA,
        'CANCELAMENTO_POR_OFICIO' => Nfse::SITUACAO_CANCELADA,
        'CANCELAMENTO_POR_SUBSTITUICAO' => Nfse::SITUACAO_SUBSTITUIDA,
    ];

    /**
     * Quando o item não traz `TipoEvento`, o código do grupo do XML resolve.
     */
    private const TIPO_POR_CODIGO = [
        '101101' => 'CANCELAMENTO',
        '105102' => 'CANCELAMENTO_POR_SUBSTITUICAO',
    ];

    public function __construct(private readonly XmlStore $store) {}

    /**
     * Importa uma NFS-e completa (raiz `NFSe`) e grava o XML no disco.
     */
    public function importarNfse(Unidade $unidade, string $xml, ?int $nsu = null): Nfse
    {
        $xp = $this->xpath($xml);

        $id = (string) $xp->query('//n:infNFSe/@Id')->item(0)?->nodeValue;
        $chave = preg_replace('/\D/', '', preg_replace('/^NFS/', '', $id));

        if (strlen($chave) !== 50) {
            throw new RuntimeException('XML de NFS-e sem chave de acesso válida.');
        }

        $dps = '//n:infNFSe/n:DPS/n:infDPS';
        $prestador = $this->documento($xp, $dps.'/n:prest') ?: $this->documento($xp, '//n:infNFSe/n:emit');
        $tomador = $this->documento($xp, $dps.'/n:toma');
        $intermediario = $this->documento($xp, $dps.'/n:interm');
        $retencao = $this->txt($xp, $dps.'/n:valores/n:trib/n:tribMun/n:tpRetISSQN');

        return DB::transaction(function () use ($unidade, $xml, $nsu, $xp, $chave, $dps, $prestador, $tomador, $intermediario, $retencao) {
            $caminho = $this->store->gravar(
                $this->store->caminhoNfse($unidade->tenant, $unidade, $chave),
                $xml,
            );

            $nfse = Nfse::updateOrCreate(
                ['unidade_id' => $unidade->id, 'chave' => $chave],
                [
                    'tenant_id' => $unidade->tenant_id,
                    'nsu' => $nsu,
                    'papel' => $this->papel($unidade, $tomador, $intermediario, $prestador),
                    'numero' => $this->txt($xp, '//n:infNFSe/n:nNFSe') ?: null,
                    'serie_dps' => $this->txt($xp, $dps.'/n:serie') ?: null,
                    'numero_dps' => $this->txt($xp, $dps.'/n:nDPS') ?: null,
                    'municipio_emissor_codigo' => $this->txt($xp, $dps.'/n:cLocEmi') ?: substr($chave, 0, 7),
                    'municipio_emissor_nome' => $this->txt($xp, '//n:infNFSe/n:xLocEmi') ?: null,
                    'municipio_incidencia_nome' => $this->txt($xp, '//n:infNFSe/n:xLocIncid') ?: null,
                    'prestador_documento' => $prestador ?: null,
                    'prestador_nome' => $this->limitar($this->txt($xp, '//n:infNFSe/n:emit/n:xNome')
                        ?: $this->txt($xp, $dps.'/n:prest/n:xNome')),
                    'tomador_documento' => $tomador ?: null,
                    'tomador_nome' => $this->limitar($this->txt($xp, $dps.'/n:toma/n:xNome')),
                    'intermediario_documento' => $intermediario ?: null,
                    'intermediario_nome' => $this->limitar($this->txt($xp, $dps.'/n:interm/n:xNome')),
                    'data_emissao' => $this->txt($xp, $dps.'/n:dhEmi') ?: null,
                    'data_processamento' => $this->txt($xp, '//n:infNFSe/n:dhProc') ?: null,
                    'competencia' => $this->txt($xp, $dps.'/n:dCompet') ?: null,
                    'codigo_servico' => $this->txt($xp, $dps.'/n:serv/n:cServ/n:cTribNac') ?: null,
                    'servico_nome' => $this->limitar($this->txt($xp, '//n:infNFSe/n:xTribNac')),
                    'descricao_servico' => $this->txt($xp, $dps.'/n:serv/n:cServ/n:xDescServ') ?: null,
                    'valor_servico' => $this->txt($xp, $dps.'/n:valores/n:vServPrest/n:vServ') ?: null,
                    'valor_liquido' => $this->txt($xp, '//n:infNFSe/n:valores/n:vLiq') ?: null,
                    'valor_iss' => $this->txt($xp, '//n:infNFSe/n:valores/n:vISSQN') ?: null,
                    // tpRetISSQN: 1 não retido; 2 retido pelo tomador; 3 retido pelo intermediário.
                    'iss_retido' => in_array($retencao, ['2', '3'], true),
                    'ambiente_gerador' => $this->txt($xp, '//n:infNFSe/n:ambGer') ?: null,
                    'versao_leiaute' => $xp->document->documentElement->getAttribute('versao') ?: null,
                    'xml_path' => $caminho,
                    'checksum' => hash('sha256', $xml),
                ]
            );

            // Eventos que chegaram antes da nota: liga e aplica a situação.
            NfseEvento::where('unidade_id', $unidade->id)
                ->where('chave', $chave)
                ->whereNull('nfse_id')
                ->update(['nfse_id' => $nfse->id]);

            $this->aplicarSituacao($nfse);

            return $nfse->fresh();
        });
    }

    /**
     * Importa um evento (raiz `evento`) e atualiza a situação da nota.
     */
    public function importarEvento(Unidade $unidade, string $xml, int $nsu, ?string $tipoEvento = null): NfseEvento
    {
        $xp = $this->xpath($xml);
        $inf = '//n:infEvento/n:pedRegEvento/n:infPedReg';

        $chave = preg_replace('/\D/', '', $this->txt($xp, $inf.'/n:chNFSe'));

        if (strlen($chave) !== 50) {
            throw new RuntimeException('XML de evento de NFS-e sem chave de acesso válida.');
        }

        // O grupo do evento é o filho `eNNNNNN` do pedido (ex.: e101101).
        $grupo = null;
        foreach ($xp->query($inf.'/*') as $filho) {
            if (preg_match('/^e(\d{6})$/', $filho->localName)) {
                $grupo = $filho;
                break;
            }
        }

        $codigo = $grupo ? substr($grupo->localName, 1) : null;
        $tipo = $tipoEvento ?: (self::TIPO_POR_CODIGO[$codigo] ?? ($codigo ? 'EVENTO_'.$codigo : 'EVENTO'));

        return DB::transaction(function () use ($unidade, $xml, $nsu, $xp, $inf, $chave, $grupo, $codigo, $tipo) {
            $caminho = $this->store->gravar(
                $this->store->caminhoEventoNfse($unidade->tenant, $unidade, $chave, $nsu),
                $xml,
            );

            $nfse = Nfse::where('unidade_id', $unidade->id)->where('chave', $chave)->first();

            $evento = NfseEvento::updateOrCreate(
                ['unidade_id' => $unidade->id, 'nsu' => $nsu],
                [
                    'tenant_id' => $unidade->tenant_id,
                    'nfse_id' => $nfse?->id,
                    'chave' => $chave,
                    'tipo_evento' => $tipo,
                    'codigo_evento' => $codigo,
                    'descricao' => $this->limitar($grupo ? $this->txtNo($xp, 'n:xDesc', $grupo) : ''),
                    'motivo' => $this->limitar($grupo ? $this->txtNo($xp, 'n:xMotivo', $grupo) : ''),
                    'data_evento' => $this->txt($xp, $inf.'/n:dhEvento') ?: ($this->txt($xp, '//n:infEvento/n:dhProc') ?: null),
                    'xml_path' => $caminho,
                    'checksum' => hash('sha256', $xml),
                ]
            );

            if ($nfse) {
                $this->aplicarSituacao($nfse);
            }

            return $evento;
        });
    }

    /**
     * Recalcula a situação da nota a partir dos eventos guardados.
     */
    private function aplicarSituacao(Nfse $nfse): void
    {
        $situacao = Nfse::SITUACAO_NORMAL;

        $tipos = NfseEvento::where('nfse_id', $nfse->id)->orderBy('nsu')->pluck('tipo_evento');
        foreach ($tipos as $tipo) {
            $situacao = self::SITUACAO_POR_EVENTO[$tipo] ?? $situacao;
        }

        if ($nfse->situacao !== $situacao) {
            $nfse->forceFill(['situacao' => $situacao])->save();
        }
    }

    /**
     * Papel da unidade na nota. A fila do ADN traz as notas recebidas, as
     * emitidas e as intermediadas pelo CNPJ; o tomador vem primeiro.
     */
    private function papel(Unidade $unidade, string $tomador, string $intermediario, string $prestador): string
    {
        $cnpj = preg_replace('/\D/', '', (string) $unidade->cnpj);

        return match ($cnpj) {
            $tomador => Nfse::PAPEL_TOMADOR,
            $intermediario => Nfse::PAPEL_INTERMEDIARIO,
            $prestador => Nfse::PAPEL_PRESTADOR,
            default => Nfse::PAPEL_OUTRO,
        };
    }

    /**
     * CNPJ, CPF ou NIF de uma parte da nota.
     */
    private function documento(DOMXPath $xp, string $parte): string
    {
        foreach (['CNPJ', 'CPF', 'NIF'] as $tag) {
            $valor = $this->txt($xp, $parte.'/n:'.$tag);
            if ($valor !== '') {
                return mb_substr($valor, 0, 40);
            }
        }

        return '';
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument;

        if (@$dom->loadXML($xml) === false) {
            throw new RuntimeException('XML de NFS-e inválido.');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', self::NS);

        return $xp;
    }

    private function txt(DOMXPath $xp, string $query): string
    {
        return trim((string) $xp->query($query)->item(0)?->nodeValue);
    }

    private function txtNo(DOMXPath $xp, string $query, \DOMNode $contexto): string
    {
        return trim((string) $xp->query($query, $contexto)->item(0)?->nodeValue);
    }

    private function limitar(string $texto): ?string
    {
        return $texto === '' ? null : mb_substr($texto, 0, 255);
    }
}

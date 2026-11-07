<?php

namespace App\Domain\Nfse;

use App\Domain\Nfe\XmlStore;
use App\Models\Nfse;
use App\Models\Unidade;
use Illuminate\Support\Facades\Cache;
use NFePHP\Common\Signer;

/**
 * Manifestação do tomador sobre uma NFS-e na SEFIN Nacional: Confirmação
 * (evento 203202) ou Rejeição (evento 203206).
 *
 * É um ato fiscal: depois de registrado o evento fica vinculado à nota e a
 * Confirmação impede o cancelamento pelo prestador (só a substituição segue
 * permitida). Por isso a chamada só vai à SEFIN depois das regras abaixo e
 * nunca é repetida automaticamente.
 *
 * Pedido (`pedRegEvento` v1.01): `Id` = `PRE` + chave + código do evento,
 * assinado em `infPedReg` com o certificado e-CNPJ da unidade (RSA-SHA1,
 * C14N, como na NF-e) e enviado com a declaração XML UTF-8, sem a qual a SEFIN
 * rejeita com E1229. Pela API a assinatura é obrigatória (E0717).
 */
class ManifestacaoNfse
{
    public const CONFIRMACAO = '203202';
    public const REJEICAO = '203206';

    /** @var array<int, string> cMotivo da Rejeição do tomador. */
    public const MOTIVOS = [
        1 => 'NFS-e em duplicidade',
        2 => 'NFS-e já emitida pelo tomador',
        3 => 'Não ocorrência do fato gerador',
        4 => 'Erro na responsabilidade tributária',
        5 => 'Erro no valor, deduções, serviço ou data',
        9 => 'Outros',
    ];

    private const XDESC = [
        self::CONFIRMACAO => 'Manifestação de NFS-e - Confirmação do Tomador',
        self::REJEICAO => 'Manifestação de NFS-e - Rejeição do Tomador',
    ];

    public function __construct(
        private readonly SefinClient $client,
        private readonly MtlsCurl $mtls,
        private readonly XmlStore $store,
    ) {}

    /**
     * @throws ManifestacaoNfseException
     */
    public function confirmar(Nfse $nfse): Nfse
    {
        return $this->registrar($nfse, self::CONFIRMACAO);
    }

    /**
     * @throws ManifestacaoNfseException
     */
    public function rejeitar(Nfse $nfse, int $motivo, ?string $descricao = null): Nfse
    {
        $descricao = $descricao === null ? null : trim($descricao);

        if (! array_key_exists($motivo, self::MOTIVOS)) {
            throw new ManifestacaoNfseException('Motivo da rejeição inválido.');
        }

        $tamanho = mb_strlen((string) $descricao);

        if ($descricao !== null && $descricao !== '' && ($tamanho < 15 || $tamanho > 255)) {
            throw new ManifestacaoNfseException('A descrição do motivo deve ter de 15 a 255 caracteres.');
        }

        if ($motivo === 9 && ($descricao === null || $descricao === '')) {
            throw new ManifestacaoNfseException('Para o motivo "Outros", descreva o motivo (de 15 a 255 caracteres).');
        }

        return $this->registrar($nfse, self::REJEICAO, $motivo, $descricao ?: null);
    }

    private function registrar(Nfse $nfse, string $codigo, ?int $motivo = null, ?string $descricao = null): Nfse
    {
        $this->validarNota($nfse);

        $unidade = Unidade::withoutGlobalScopes()->findOrFail($nfse->unidade_id);

        // Um clique duplo não pode gerar dois pedidos (a segunda tentativa
        // voltaria da SEFIN como conflito de eventos, E0861/E0866).
        $trava = Cache::lock('nfse:manifestacao:'.$nfse->id, 60);

        if (! $trava->get()) {
            throw new ManifestacaoNfseException('Já existe uma manifestação sendo enviada para esta nota. Aguarde.');
        }

        try {
            $pedido = $this->pedidoAssinado($unidade, $nfse, $codigo, $motivo, $descricao);
            $resposta = $this->client->registrarEvento($unidade, $nfse->chave, $pedido);
        } finally {
            $trava->release();
        }

        if (! $resposta->registrado()) {
            throw new ManifestacaoNfseException($resposta->mensagemErro());
        }

        // O evento já está registrado na SEFIN: o estado local vem primeiro, e
        // uma falha ao guardar o XML não pode fazer o pedido parecer não feito.
        $nfse->forceFill([
            'manifestacao' => $codigo === self::CONFIRMACAO ? Nfse::MANIFESTACAO_CONFIRMADA : Nfse::MANIFESTACAO_REJEITADA,
            'manifestada_em' => now(),
        ])->save();

        if ($resposta->eventoXml !== null) {
            $this->guardarEvento($unidade, $nfse, $codigo, $resposta->eventoXml);
        }

        return $nfse->fresh();
    }

    private function validarNota(Nfse $nfse): void
    {
        if ($nfse->papel !== Nfse::PAPEL_TOMADOR) {
            throw new ManifestacaoNfseException('Só é possível manifestar notas em que a unidade é a tomadora do serviço.');
        }

        if ($nfse->situacao !== Nfse::SITUACAO_NORMAL) {
            throw new ManifestacaoNfseException('Não é possível manifestar uma nota cancelada ou substituída.');
        }

        if ($nfse->manifestacao !== null) {
            throw new ManifestacaoNfseException('Esta nota já foi manifestada pelo tomador.');
        }
    }

    /**
     * Monta e assina o `pedRegEvento`. Devolve o XML com a declaração UTF-8.
     */
    public function pedidoAssinado(Unidade $unidade, Nfse $nfse, string $codigo, ?int $motivo, ?string $descricao): string
    {
        $grupo = '<xDesc>'.self::XDESC[$codigo].'</xDesc>';

        if ($codigo === self::REJEICAO) {
            $grupo .= '<cMotivo>'.$motivo.'</cMotivo>';
            if ($descricao !== null) {
                $grupo .= '<xMotivo>'.htmlspecialchars($descricao, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</xMotivo>';
            }
        }

        // Alguns segundos no passado: um relógio local adiantado em relação à
        // SEFIN faria o pedido ser rejeitado por data futura.
        $dhEvento = now('America/Sao_Paulo')->subSeconds(10)->format('Y-m-d\TH:i:sP');
        $cnpj = preg_replace('/\D/', '', (string) $unidade->cnpj);

        $xml = '<pedRegEvento xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">'
            .'<infPedReg Id="PRE'.$nfse->chave.$codigo.'">'
            .'<tpAmb>'.((int) ($unidade->tp_amb ?? 2) === 1 ? 1 : 2).'</tpAmb>'
            .'<verAplic>nfegov/'.config('nfegov.versao.numero').'</verAplic>'
            .'<dhEvento>'.$dhEvento.'</dhEvento>'
            .'<CNPJAutor>'.$cnpj.'</CNPJAutor>'
            .'<chNFSe>'.$nfse->chave.'</chNFSe>'
            .'<e'.$codigo.'>'.$grupo.'</e'.$codigo.'>'
            .'</infPedReg>'
            .'</pedRegEvento>';

        $assinado = Signer::sign(
            $this->mtls->certificado($unidade),
            $xml,
            'infPedReg',
            'Id',
            OPENSSL_ALGO_SHA1,
        );

        return '<?xml version="1.0" encoding="UTF-8"?>'.$assinado;
    }

    /**
     * Guarda o evento devolvido pela SEFIN (documento de guarda, imutável).
     */
    private function guardarEvento(Unidade $unidade, Nfse $nfse, string $codigo, string $xml): void
    {
        try {
            $this->store->gravar(
                $this->store->caminhoManifestacaoNfse($unidade->tenant, $unidade, $nfse->chave, $codigo),
                $xml,
            );
        } catch (Throwable $e) {
            Log::error('NFS-e: manifestação registrada na SEFIN, mas o XML do evento não foi guardado.', [
                'nfse_id' => $nfse->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}

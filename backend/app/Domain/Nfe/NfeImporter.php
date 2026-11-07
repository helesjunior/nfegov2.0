<?php

namespace App\Domain\Nfe;

use App\Models\Fornecedor;
use App\Models\Nfe;
use App\Models\NfeItem;
use App\Models\Nsu;
use App\Models\Unidade;
use Illuminate\Support\Facades\DB;

/**
 * Importa XMLs de NF-e (completos ou resumos) para o banco.
 *
 * A identidade da nota é (unidade, chave): a NF-e pertence à UNIDADE que a
 * recebeu, não à entidade. Se a mesma nota chega para duas unidades da mesma
 * entidade (ex.: transferência entre filiais), cada uma tem o seu registro e o
 * seu XML, na sua própria pasta. Dentro da unidade, `updateOrCreate` garante
 * que a nota nunca duplique (mesmo chegando em resumo e depois completa). Itens são sincronizados para não duplicarem em reprocessamentos.
 */
class NfeImporter
{
    public function __construct(private readonly XmlStore $store)
    {
    }

    /**
     * Importa um XML COMPLETO (procNFe/nfeProc) e grava o XML no disco.
     */
    public function import(Unidade $unidade, ?Nsu $nsu, string $xml): Nfe
    {
        $dom = $this->dom($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $inf = $xp->query('//nfe:infNFe')->item(0);
        $chave = preg_replace('/^NFe/', '', $inf?->attributes?->getNamedItem('Id')?->nodeValue ?? '');
        $chave = preg_replace('/\D/', '', $chave);

        if (strlen($chave) !== 44) {
            throw new \RuntimeException('XML sem chave de acesso válida.');
        }

        return DB::transaction(function () use ($unidade, $nsu, $xml, $chave, $xp) {
            $caminho = $this->store->existe($this->store->caminho($unidade->tenant, $unidade, $chave))
                ? $this->store->caminho($unidade->tenant, $unidade, $chave)
                : $this->store->put($unidade->tenant, $unidade, $chave, $xml);

            $emitCnpj = $this->txt($xp, '//nfe:emit/nfe:CNPJ');

            $nfe = Nfe::updateOrCreate(
                ['unidade_id' => $unidade->id, 'chave' => $chave],
                [
                    'tenant_id' => $unidade->tenant_id,
                    'unidade_id' => $unidade->id,
                    'nsu_id' => $nsu?->id,
                    'fornecedor_id' => $this->fornecedor($unidade, $emitCnpj, $xp)->id,
                    'numero' => $this->txt($xp, '//nfe:ide/nfe:nNF') ?: null,
                    'serie' => $this->txt($xp, '//nfe:ide/nfe:serie') ?: null,
                    'modelo' => $this->txt($xp, '//nfe:ide/nfe:mod') ?: '55',
                    'emitente_cnpj' => $emitCnpj ?: null,
                    'emitente_nome' => $this->txt($xp, '//nfe:emit/nfe:xNome') ?: null,
                    'dest_cnpj' => $this->txt($xp, '//nfe:dest/nfe:CNPJ') ?: null,
                    'valor_total' => $this->txt($xp, '//nfe:total/nfe:ICMSTot/nfe:vNF') ?: null,
                    'natureza_operacao' => $this->txt($xp, '//nfe:ide/nfe:natOp') ?: null,
                    'data_emissao' => $this->txt($xp, '//nfe:ide/nfe:dhEmi') ?: null,
                    'cstat' => $this->txt($xp, '//nfe:protNFe/nfe:infProt/nfe:cStat') ?: null,
                    'estado' => Nfe::ESTADO_COMPLETO,
                    'emitente_proprio' => $this->emitenteProprio($unidade, $emitCnpj),
                    'xml_path' => $caminho,
                    'checksum' => hash('sha256', $xml),
                    'schema_name' => 'nfeProc',
                ]
            );

            $this->sincronizarItens($nfe, $xp);

            return $nfe->fresh(['itens']);
        });
    }

    /**
     * Importa um RESUMO (resNFe): sem itens/impostos completos.
     */
    public function importResumo(Unidade $unidade, ?Nsu $nsu, string $xml): Nfe
    {
        $dom = $this->dom($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $chave = preg_replace('/\D/', '', $this->txt($xp, '//nfe:resNFe/nfe:chNFe'));

        if (strlen($chave) !== 44) {
            throw new \RuntimeException('Resumo sem chave de acesso válida.');
        }

        $emitCnpj = $this->txt($xp, '//nfe:resNFe/nfe:CNPJ');

        // Resumo NÃO grava XML no disco (não é documento de guarda completa).
        // Se a unidade já tem a nota completa, um resumo tardio (reentrega ou
        // recuperação) não pode rebaixá-la para "resumo".
        $existente = Nfe::where('unidade_id', $unidade->id)->where('chave', $chave)->first();
        if ($existente?->completo()) {
            return $existente;
        }

        return Nfe::updateOrCreate(
            ['unidade_id' => $unidade->id, 'chave' => $chave],
            [
                'tenant_id' => $unidade->tenant_id,
                'unidade_id' => $unidade->id,
                'nsu_id' => $nsu?->id,
                'emitente_cnpj' => $emitCnpj ?: null,
                'emitente_nome' => $this->txt($xp, '//nfe:resNFe/nfe:xNome') ?: null,
                'valor_total' => $this->txt($xp, '//nfe:resNFe/nfe:vNF') ?: null,
                'data_emissao' => $this->txt($xp, '//nfe:resNFe/nfe:dhEmi') ?: null,
                'estado' => Nfe::ESTADO_RESUMO,
                'emitente_proprio' => $this->emitenteProprio($unidade, $emitCnpj),
            ]
        );
    }

    /**
     * Sincroniza os itens (substitui o conjunto pelo do XML, sem duplicar).
     */
    private function sincronizarItens(Nfe $nfe, \DOMXPath $xp): void
    {
        $det = $xp->query('//nfe:infNFe/nfe:det');

        $nfe->itens()->delete();

        $seq = 0;
        foreach ($det as $item) {
            $seq++;
            $nItem = $item->attributes->getNamedItem('nItem')?->nodeValue ?? (string) $seq;

            $nfe->itens()->create([
                'tenant_id' => $nfe->tenant_id,
                'sequencial' => (int) $nItem,
                'descricao' => $this->txtNode($xp, 'nfe:prod/nfe:xProd', $item) ?: 'Item',
                'cfop' => $this->txtNode($xp, 'nfe:prod/nfe:CFOP', $item) ?: null,
                'unidade_medida' => $this->txtNode($xp, 'nfe:prod/nfe:uCom', $item) ?: null,
                'quantidade' => $this->txtNode($xp, 'nfe:prod/nfe:qCom', $item) ?: null,
                'valor_unitario' => $this->txtNode($xp, 'nfe:prod/nfe:vUnCom', $item) ?: null,
                'valor_total' => $this->txtNode($xp, 'nfe:prod/nfe:vProd', $item) ?: null,
            ]);
        }
    }

    /**
     * Garante o fornecedor (emitente) do tenant e devolve o registro.
     */
    private function fornecedor(Unidade $unidade, string $cnpj, \DOMXPath $xp): ?Fornecedor
    {
        if ($cnpj === '') {
            return null;
        }

        // Não criamos fornecedor para o próprio emitente (nota própria).
        if ($this->emitenteProprio($unidade, $cnpj)) {
            return null;
        }

        return Fornecedor::firstOrCreate(
            ['cnpj' => $cnpj],
            [
                'tenant_id' => $unidade->tenant_id,
                'nome' => $this->txt($xp, '//nfe:emit/nfe:xNome') ?: 'Fornecedor',
                'ie' => $this->txt($xp, '//nfe:emit/nfe:IE') ?: null,
            ]
        );
    }

    private function emitenteProprio(Unidade $unidade, string $emitCnpj): bool
    {
        return $emitCnpj !== ''
            && preg_replace('/\D/', '', (string) $unidade->cnpj) === $emitCnpj;
    }

    private function dom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        return $dom;
    }

    private function txt(\DOMXPath $xp, string $query): string
    {
        return trim((string) $xp->query($query)->item(0)?->nodeValue);
    }

    private function txtNode(\DOMXPath $xp, string $query, \DOMNode $contexto): string
    {
        return trim((string) $xp->query($query, $contexto)->item(0)?->nodeValue);
    }
}
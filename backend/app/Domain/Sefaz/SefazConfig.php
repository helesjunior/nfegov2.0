<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;

/**
 * Monta o JSON de configuração esperado pelo `NFePHP\NFe\Tools` (sped-nfe).
 *
 * Os dados vêm da UNIDADE (não do tenant): cada CNPJ tem sua própria UF,
 * inscrição estadual e ambiente (produção/homologação) junto à SEFAZ.
 *
 * `schemes` e `versao` seguem o layout 4.00 (padrão vigente da NF-e).
 */
class SefazConfig
{
    /**
     * @return array<string, mixed>
     */
    public function paraUnidade(Unidade $unidade): array
    {
        return [
            'atualizacao' => now()->format('Y-m-d H:i:s'),
            'tpAmb' => (int) ($unidade->tp_amb ?? 2),
            'razaosocial' => (string) $unidade->nome,
            'cnpj' => preg_replace('/\D/', '', (string) $unidade->cnpj),
            'ie' => (string) ($unidade->ie ?? ''),
            'siglaUF' => strtoupper((string) ($unidade->uf ?? 'SP')),
            'schemes' => config('nfegov.sefaz.schemes', 'PL_009_V4'),
            'versao' => config('nfegov.sefaz.versao', '4.00'),
            'tokenNS' => '',
        ];
    }

    /**
     * JSON pronto para o construtor do Tools.
     */
    public function json(Unidade $unidade): string
    {
        return json_encode($this->paraUnidade($unidade), JSON_UNESCAPED_UNICODE);
    }
}
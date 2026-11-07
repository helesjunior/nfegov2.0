<?php

namespace App\Domain\Nfse;

use App\Models\Unidade;
use RuntimeException;

/**
 * Cliente HTTP da API de contribuintes do ADN, com mTLS (`MtlsCurl`).
 *
 * `cnpjConsulta` vai sempre com o CNPJ da unidade. O ADN aceita certificado
 * de outro estabelecimento da mesma raiz (matriz consultando filial).
 */
class HttpAdnClient implements AdnClient
{
    public function __construct(private readonly MtlsCurl $mtls) {}

    public function distribuicao(Unidade $unidade, int $ultimoNsu): RespostaAdn
    {
        return $this->get($unidade, '/DFe/'.max(0, $ultimoNsu), [
            'lote' => 'true',
            'cnpjConsulta' => $this->cnpj($unidade),
        ]);
    }

    public function porChave(Unidade $unidade, string $chave): RespostaAdn
    {
        $chave = preg_replace('/\D/', '', $chave);

        if (strlen($chave) !== 50) {
            throw new RuntimeException('A chave da NFS-e deve ter 50 dígitos.');
        }

        return $this->get($unidade, '/NFSe/'.$chave.'/Eventos', []);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function get(Unidade $unidade, string $caminho, array $query): RespostaAdn
    {
        $base = rtrim((string) config('nfegov.adn.base_url.'.((int) ($unidade->tp_amb ?? 2) === 1 ? 1 : 2)), '/');
        $url = $base.$caminho.($query === [] ? '' : '?'.http_build_query($query));

        [$http, $corpo] = $this->mtls->enviar('GET', $url, $this->mtls->certificado($unidade));

        return RespostaAdn::deCorpo($http, $corpo);
    }

    private function cnpj(Unidade $unidade): string
    {
        return preg_replace('/\D/', '', (string) $unidade->cnpj);
    }
}

<?php

namespace App\Domain\Nfse;

use App\Domain\Certificate\CertificateLoader;
use App\Models\Unidade;
use RuntimeException;

/**
 * Cliente HTTP da API de contribuintes do ADN, com mTLS.
 *
 * O certificado e-CNPJ da unidade é aberto em MEMÓRIA (`CertificateLoader`) e
 * entregue ao cURL como blob PEM (`CURLOPT_SSLCERT_BLOB`/`CURLOPT_SSLKEY_BLOB`):
 * o PFX e a chave privada nunca tocam o disco em claro.
 *
 * `cnpjConsulta` vai sempre com o CNPJ da unidade. O ADN aceita certificado
 * de outro estabelecimento da mesma raiz (matriz consultando filial).
 */
class HttpAdnClient implements AdnClient
{
    public function __construct(private readonly CertificateLoader $loader) {}

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
        $certificado = $this->certificado($unidade);
        $base = rtrim((string) config('nfegov.adn.base_url.'.((int) ($unidade->tp_amb ?? 2) === 1 ? 1 : 2)), '/');

        $url = $base.$caminho.($query === [] ? '' : '?'.http_build_query($query));

        // cURL direto: o Guzzle não aceita as opções de certificado em blob.
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) config('nfegov.adn.timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLCERT_BLOB => (string) $certificado,
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_SSLKEY_BLOB => (string) $certificado->privateKey,
        ]);

        $corpo = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if ($corpo === false || $http === 0) {
            throw new RuntimeException('Falha de conexão com o ADN: '.($erro ?: 'sem resposta').'.');
        }

        return RespostaAdn::deCorpo($http, (string) $corpo);
    }

    private function certificado(Unidade $unidade): \NFePHP\Common\Certificate
    {
        $certificate = $unidade->certificates()->where('ativo', true)->latest('id')->first();

        if (! $certificate) {
            throw new RuntimeException("A unidade {$unidade->id} não possui certificado ativo para consultar o ADN.");
        }

        if ($certificate->valido_ate !== null && $certificate->valido_ate->isPast()) {
            throw new RuntimeException("O certificado e-CNPJ da unidade {$unidade->id} está vencido; consulta ao ADN suspensa.");
        }

        return $this->loader->load($certificate);
    }

    private function cnpj(Unidade $unidade): string
    {
        return preg_replace('/\D/', '', (string) $unidade->cnpj);
    }
}

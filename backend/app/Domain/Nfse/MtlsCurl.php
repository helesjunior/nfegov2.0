<?php

namespace App\Domain\Nfse;

use App\Domain\Certificate\CertificateLoader;
use App\Models\Unidade;
use NFePHP\Common\Certificate as SpedCertificate;
use RuntimeException;

/**
 * Chamadas HTTPS com certificado de cliente (mTLS) para os serviços da
 * NFS-e Nacional (ADN e SEFIN Nacional).
 *
 * O certificado e-CNPJ da unidade é aberto em MEMÓRIA (`CertificateLoader`) e
 * entregue ao cURL como blob PEM (`CURLOPT_SSLCERT_BLOB`/`CURLOPT_SSLKEY_BLOB`):
 * o PFX e a chave privada nunca tocam o disco em claro. Usa o cURL direto
 * porque o Guzzle não aceita as opções de certificado em blob.
 */
class MtlsCurl
{
    public function __construct(private readonly CertificateLoader $loader) {}

    /**
     * Certificado ativo e vigente da unidade, aberto em memória.
     *
     * @throws RuntimeException sem certificado ativo ou com certificado vencido.
     */
    public function certificado(Unidade $unidade): SpedCertificate
    {
        $certificate = $unidade->certificates()->where('ativo', true)->latest('id')->first();

        if (! $certificate) {
            throw new RuntimeException("A unidade {$unidade->id} não possui certificado ativo para falar com a NFS-e Nacional.");
        }

        if ($certificate->valido_ate !== null && $certificate->valido_ate->isPast()) {
            throw new RuntimeException("O certificado e-CNPJ da unidade {$unidade->id} está vencido; chamada à NFS-e Nacional suspensa.");
        }

        return $this->loader->load($certificate);
    }

    /**
     * @param  array<int, string>  $cabecalhos
     * @return array{0: int, 1: string} HTTP e corpo da resposta.
     *
     * @throws RuntimeException em falha de conexão (sem resposta HTTP).
     */
    public function enviar(
        string $metodo,
        string $url,
        SpedCertificate $certificado,
        ?string $corpo = null,
        array $cabecalhos = ['Accept: application/json'],
    ): array {
        $ch = curl_init($url);

        $opcoes = [
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) config('nfegov.adn.timeout', 30),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSLCERTTYPE => 'PEM',
            CURLOPT_SSLCERT_BLOB => (string) $certificado,
            CURLOPT_SSLKEYTYPE => 'PEM',
            CURLOPT_SSLKEY_BLOB => (string) $certificado->privateKey,
        ];

        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = $corpo;
        }

        curl_setopt_array($ch, $opcoes);

        $resposta = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if ($resposta === false || $http === 0) {
            throw new RuntimeException('Falha de conexão com a NFS-e Nacional: '.($erro ?: 'sem resposta').'.');
        }

        return [$http, (string) $resposta];
    }
}

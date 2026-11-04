<?php

namespace App\Domain\Sefaz;

use App\Domain\Certificate\CertificateLoader;
use App\Models\Unidade;
use NFePHP\NFe\Tools;
use RuntimeException;

/**
 * Constrói o `NFePHP\NFe\Tools` (sped-nfe) para uma unidade.
 *
 * O certificado e-CNPJ é carregado em MEMÓRIA (`CertificateLoader`) a partir
 * dos dados cifrados — o PFX e a senha nunca tocam o disco em claro.
 *
 * Este é o ponto onde toda integração com a SEFAZ começa; injeta o loader
 * para permitir substituição em testes.
 */
class ToolsFactory
{
    public function __construct(
        private readonly SefazConfig $config,
        private readonly CertificateLoader $loader,
    ) {
    }

    /**
     * @throws RuntimeException quando a unidade não possui certificado ativo.
     */
    public function make(Unidade $unidade): Tools
    {
        $certificate = $unidade->certificate()->first()
            ?? $unidade->certificates()->where('ativo', true)->latest('id')->first();

        if (! $certificate) {
            throw new RuntimeException(
                "A unidade {$unidade->id} não possui certificado ativo para falar com a SEFAZ."
            );
        }

        // Última linha de defesa: nunca montar o Tools com certificado vencido.
        // Sem isto, a chamada seguiria para a SEFAZ, consumiria NSU e seria
        // rejeitada — o caminho do bloqueio por "consumo indevido" (cStat 656).
        if ($certificate->valido_ate !== null && $certificate->valido_ate->isPast()) {
            throw new RuntimeException(
                "O certificado e-CNPJ da unidade {$unidade->id} está vencido; sincronização suspensa."
            );
        }

        $spedCertificate = $this->loader->load($certificate);

        $tools = new Tools($this->config->json($unidade), $spedCertificate);

        // NF-e modelo 55 e ambiente da unidade.
        $tools->model(55);
        $tools->setEnvironment((int) ($unidade->tp_amb ?? 2));

        return $tools;
    }
}
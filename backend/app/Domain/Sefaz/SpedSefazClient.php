<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;
use NFePHP\NFe\Tools;

/**
 * Implementação de `SefazClient` sobre o `NFePHP\NFe\Tools` (sped-nfe).
 *
 * O `Tools` é montado por unidade via `ToolsFactory` (com o certificado
 * carregado em memória).
 */
class SpedSefazClient implements SefazClient
{
    public function __construct(private readonly ToolsFactory $factory)
    {
    }

    public function distDFe(Unidade $unidade, int $ultimoNsu): string
    {
        $tools = $this->factory->make($unidade);

        return $this->distDFeCom($tools, $unidade, $ultimoNsu);
    }

    public function distDFeCom(Tools $tools, Unidade $unidade, int $ultimoNsu): string
    {
        // (ultNSU, numNSU=0, chave='', fonte='AN') — consulta incremental padrão.
        // A fonte PRECISA ser 'AN' (Ambiente Nacional): o sped-nfe repassa esse
        // valor ao resolvedor de autorizador; vazio, falha com
        // "Nao existe autorizador [] para os webservices do modelo [55]".
        return (string) $tools->sefazDistDFe($ultimoNsu, 0, '', 'AN');
    }

    public function manifestarCiencia(Unidade $unidade, string $chave): bool
    {
        $tools = $this->factory->make($unidade);

        try {
            // Evento 210210 (Ciência da Operação), sem justificativa, 1ª sequência.
            $tools->sefazManifesta($chave, 210210, '', 1, now());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function baixarPorChave(Unidade $unidade, string $chave): ?string
    {
        $tools = $this->factory->make($unidade);

        $retorno = (string) $tools->sefazDownload($chave);

        return $retorno !== '' ? $retorno : null;
    }
}
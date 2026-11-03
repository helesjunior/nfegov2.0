<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;
use NFePHP\NFe\Tools;

/**
 * Consultas pontuais à SEFAZ.
 *
 * Por ora, a consulta de uma NF-e pela chave de acesso. Devolve o XML de
 * retorno bruto (a interpretação/importação fica no domínio de NF-e).
 */
class ConsultaService
{
    public function __construct(private readonly ToolsFactory $factory)
    {
    }

    /**
     * Consulta uma NF-e pela chave (44 dígitos) e devolve o XML de retorno.
     */
    public function porChave(Unidade $unidade, string $chave): string
    {
        $chave = preg_replace('/\D/', '', $chave);

        $tools = $this->factory->make($unidade);

        return $this->consultarCom($tools, $unidade, $chave);
    }

    /**
     * Executa a consulta com um Tools já montado (facilita testes).
     */
    public function consultarCom(Tools $tools, Unidade $unidade, string $chave): string
    {
        return (string) $tools->sefazConsultaChave(
            $chave,
            (int) ($unidade->tp_amb ?? 2),
        );
    }
}
<?php

namespace App\Domain\Sefaz;

use App\Models\Unidade;

/**
 * Abstração da comunicação com a SEFAZ.
 *
 * Isola o domínio da implementação concreta (`NFePHP\NFe\Tools`), permitindo
 * substituição em testes e o tratamento do freio anti-bloqueio sem espalhar
 * `sleep`/retry pelo código de negócio.
 */
interface SefazClient
{
    /**
     * Consulta incremental do distDFe. Devolve o XML de retorno cru
     * (`retDistDFeInt`), a partir do último NSU consumido.
     */
    public function distDFe(Unidade $unidade, int $ultimoNsu): string;

    /**
     * Manifesta ciência da operação (evento 210210). Devolve true em sucesso.
     */
    public function manifestarCiencia(Unidade $unidade, string $chave): bool;

    /**
     * Baixa o XML completo de uma NF-e pela chave. Devolve o XML ou null
     * quando indisponível (ex.: cStat != 138).
     */
    public function baixarPorChave(Unidade $unidade, string $chave): ?string;
}
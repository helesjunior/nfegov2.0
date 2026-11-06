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
     * Consulta por um NSU ESPECÍFICO (`consNSU`). Devolve o `retDistDFeInt`
     * cru. Usada para recuperar um documento já consumido da fila, sem tocar
     * o cursor incremental da unidade.
     *
     * Atenção: NSU 0 NÃO é uma consulta por NSU (o sped-nfe cai para a
     * varredura incremental); use valores >= 1.
     */
    public function distDFePorNsu(Unidade $unidade, int $nsu): string;

    /**
     * Consulta pela CHAVE de acesso (`consChNFe`). Devolve o `retDistDFeInt`
     * cru. Sem Ciência prévia pode devolver apenas o resumo.
     */
    public function distDFePorChave(Unidade $unidade, string $chave): string;

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
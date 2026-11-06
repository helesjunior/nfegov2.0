<?php

namespace Tests\Feature\Nfe;

use App\Domain\Sefaz\ResultadoRecuperacao;
use App\Notifications\RecuperacaoConcluida;
use PHPUnit\Framework\TestCase;

/**
 * Notificação do desfecho de uma recuperação manual de NF-e.
 *
 * Canal `database` apenas. O payload tem `tipo`/`mensagem`/`status` e NÃO tem
 * `caminho` (para não ser confundida com notificação de exportação).
 */
class RecuperacaoConcluidaTest extends TestCase
{
    public function test_payload_tem_tipo_e_mensagem(): void
    {
        $n = new RecuperacaoConcluida('chave x', ResultadoRecuperacao::de(ResultadoRecuperacao::ENCONTRADO, 1));
        $a = $n->toArray(new \stdClass);

        $this->assertSame('recuperacao', $a['tipo']);
        $this->assertArrayHasKey('mensagem', $a);
        $this->assertSame(ResultadoRecuperacao::ENCONTRADO, $a['status']);
        $this->assertArrayNotHasKey('caminho', $a);
    }

    public function test_usa_canal_database(): void
    {
        $n = new RecuperacaoConcluida('x', ResultadoRecuperacao::de(ResultadoRecuperacao::NAO_ENCONTRADO));
        $this->assertContains('database', $n->via(new \stdClass));
    }
}
<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Endpoint de versão (`GET /api/version`).
 *
 * A versão semântica vem do arquivo `VERSION` (fonte de verdade única) e o
 * timestamp da build do `build.json`. O endpoint é PÚBLICO: precisa responder
 * antes do login (o rodapé aparece já na tela de entrada).
 */
class VersaoApiTest extends TestCase
{
    public function test_expoe_versao_e_rotulo_publicamente(): void
    {
        // O endpoint não exige autenticação.
        $resp = $this->getJson('/api/version');

        $resp->assertOk();
        $resp->assertJsonStructure(['data' => ['versao', 'build_at', 'rotulo']]);

        // A versão inicial do sistema é 2.0.0.
        $this->assertSame('2.0.0', $resp->json('data.versao'));
    }

    public function test_rotulo_inclui_o_timestamp_da_build_quando_existe(): void
    {
        // Simula uma build gravada: a config passa a refletir `build.json`.
        config()->set('nfegov.versao.numero', '2.0.0');
        config()->set('nfegov.versao.build_at', '20261106.175300');

        $resp = $this->getJson('/api/version');

        $resp->assertOk();
        $this->assertSame('2.0.0-20261106.175300', $resp->json('data.rotulo'));
        $this->assertSame('20261106.175300', $resp->json('data.build_at'));
    }

    public function test_sem_build_gravada_o_rotulo_e_apenas_a_versao(): void
    {
        config()->set('nfegov.versao.numero', '2.0.0');
        config()->set('nfegov.versao.build_at', null);

        $resp = $this->getJson('/api/version');

        $resp->assertOk();
        $this->assertNull($resp->json('data.build_at'));
        $this->assertSame('2.0.0', $resp->json('data.rotulo'));
    }
}

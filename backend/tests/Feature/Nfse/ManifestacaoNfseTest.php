<?php

namespace Tests\Feature\Nfse;

use App\Domain\Certificate\CertificateService;
use App\Domain\Nfse\ManifestacaoNfse;
use App\Domain\Nfse\ManifestacaoNfseException;
use App\Domain\Nfse\RespostaSefin;
use App\Domain\Nfse\SefinClient;
use App\Models\Nfse;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use NFePHP\Common\Signer;
use Tests\TestCase;

/**
 * Manifestação do tomador (Confirmação 203202 / Rejeição 203206) na SEFIN.
 *
 * Nenhum teste chama a SEFIN: o cliente é um fake que guarda o pedido enviado,
 * para conferir o XML (Id, campos, assinatura e declaração UTF-8) sem registrar
 * evento fiscal de verdade.
 */
class ManifestacaoNfseTest extends TestCase
{
    use NfseXml;
    use RefreshDatabase;

    /** @var array<int, array{chave:string, xml:string}> */
    public array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesSeeder::class);
    }

    private function unidade(int $tpAmb = 2): Unidade
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
            'tp_amb' => $tpAmb,
        ]);

        app(CertificateService::class)->store(
            $unidade,
            new UploadedFile(base_path('tests/Fixtures/cert_e_cnpj.pfx'), 'certificado.pfx', 'application/x-pkcs12', null, true),
            'senha-pfx',
        );

        return $unidade;
    }

    private function nota(Unidade $unidade, array $attrs = []): Nfse
    {
        return Nfse::factory()->create(array_merge([
            'unidade_id' => $unidade->id,
            'chave' => $this->chaveNfse('99888777000166', 7),
            'papel' => Nfse::PAPEL_TOMADOR,
            'tomador_documento' => $unidade->cnpj,
        ], $attrs));
    }

    /**
     * Fake da SEFIN: devolve a resposta combinada e guarda o que recebeu.
     */
    private function sefin(int $http, string $corpo): void
    {
        $teste = $this;

        $this->app->instance(SefinClient::class, new class($http, $corpo, $teste) implements SefinClient
        {
            public function __construct(private int $http, private string $corpo, private ManifestacaoNfseTest $teste) {}

            public function registrarEvento(Unidade $unidade, string $chave, string $pedidoXmlAssinado): RespostaSefin
            {
                $this->teste->enviados[] = ['chave' => $chave, 'xml' => $pedidoXmlAssinado];

                return RespostaSefin::deCorpo($this->http, $this->corpo);
            }
        });
    }

    private function sucesso(): string
    {
        return json_encode([
            'tipoAmbiente' => 2,
            'versaoAplicativo' => 'SefinNacional_1.6.0',
            'dataHoraProcessamento' => '2026-11-07T16:10:46-03:00',
            'eventoXmlGZipB64' => base64_encode(gzencode('<?xml version="1.0" encoding="utf-8"?><evento versao="1.01"/>')),
        ]);
    }

    public function test_confirmacao_envia_pedido_assinado_no_formato_da_sefin(): void
    {
        $unidade = $this->unidade(tpAmb: 2);
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());

        $atualizada = app(ManifestacaoNfse::class)->confirmar($nfse);

        $this->assertCount(1, $this->enviados);
        $xml = $this->enviados[0]['xml'];

        // A SEFIN rejeita sem a declaração UTF-8 (E1229) e sem assinatura (E0717).
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?><pedRegEvento', $xml);
        $this->assertTrue(Signer::signatureCheck($xml), 'A assinatura do pedido deve ser válida.');

        $dom = new \DOMDocument;
        $dom->loadXML($xml);
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        $this->assertSame('1.01', $dom->documentElement->getAttribute('versao'));
        $this->assertSame('PRE'.$nfse->chave.'203202', $xp->query('//n:infPedReg/@Id')->item(0)->nodeValue);
        $this->assertSame('2', $xp->query('//n:tpAmb')->item(0)->nodeValue);
        $this->assertSame('11222333000181', $xp->query('//n:CNPJAutor')->item(0)->nodeValue);
        $this->assertSame($nfse->chave, $xp->query('//n:chNFSe')->item(0)->nodeValue);
        $this->assertSame('Manifestação de NFS-e - Confirmação do Tomador', $xp->query('//n:e203202/n:xDesc')->item(0)->nodeValue);
        $this->assertSame(0, $xp->query('//n:e203206')->length);
        // A assinatura é irmã de infPedReg, dentro de pedRegEvento.
        $this->assertSame(1, $xp->query('/n:pedRegEvento/*[local-name()="Signature"]')->length);

        $this->assertSame(Nfse::MANIFESTACAO_CONFIRMADA, $atualizada->manifestacao);
        $this->assertNotNull($atualizada->manifestada_em);
        $this->assertSame($nfse->chave, $this->enviados[0]['chave']);
    }

    public function test_ambiente_de_producao_vai_no_pedido(): void
    {
        $unidade = $this->unidade(tpAmb: 1);
        $this->sefin(201, $this->sucesso());

        app(ManifestacaoNfse::class)->confirmar($this->nota($unidade));

        $this->assertStringContainsString('<tpAmb>1</tpAmb>', $this->enviados[0]['xml']);
    }

    public function test_rejeicao_leva_motivo_e_descricao(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());

        $atualizada = app(ManifestacaoNfse::class)->rejeitar($nfse, 4, 'Valor cobrado diferente do contratado');

        $xml = $this->enviados[0]['xml'];
        $this->assertTrue(Signer::signatureCheck($xml));
        $this->assertStringContainsString('<infPedReg Id="PRE'.$nfse->chave.'203206">', $xml);
        $this->assertStringContainsString('<e203206><xDesc>Manifestação de NFS-e - Rejeição do Tomador</xDesc><cMotivo>4</cMotivo><xMotivo>Valor cobrado diferente do contratado</xMotivo></e203206>', $xml);
        $this->assertSame(Nfse::MANIFESTACAO_REJEITADA, $atualizada->manifestacao);
    }

    public function test_rejeicao_sem_descricao_omite_xmotivo(): void
    {
        $unidade = $this->unidade();
        $this->sefin(201, $this->sucesso());

        app(ManifestacaoNfse::class)->rejeitar($this->nota($unidade), 1);

        $this->assertStringContainsString('<cMotivo>1</cMotivo></e203206>', $this->enviados[0]['xml']);
        $this->assertStringNotContainsString('xMotivo', $this->enviados[0]['xml']);
    }

    public function test_descricao_com_caracteres_especiais_e_escapada(): void
    {
        $unidade = $this->unidade();
        $this->sefin(201, $this->sucesso());

        app(ManifestacaoNfse::class)->rejeitar($this->nota($unidade), 9, 'Serviço <não> prestado & cobrado "duas" vezes');

        $xml = $this->enviados[0]['xml'];
        $this->assertTrue(Signer::signatureCheck($xml));
        $this->assertStringContainsString('Serviço &lt;não&gt; prestado &amp; cobrado', $xml);
    }

    public function test_guarda_o_evento_devolvido_pela_sefin(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());

        app(ManifestacaoNfse::class)->confirmar($nfse);

        Storage::disk('local')->assertExists(
            "tenants/{$unidade->tenant_id}/unidades/{$unidade->id}/nfses/2023/10/eventos/{$nfse->chave}-manifestacao-203202.xml"
        );
    }

    public function test_rejeicao_da_sefin_vira_erro_e_nao_muda_a_nota(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(400, json_encode([
            'tipoAmbiente' => 2,
            'versaoAplicativo' => 'SefinNacional_1.6.0',
            'erro' => [['codigo' => 'E0861', 'descricao' => 'Evento conflitante']],
        ]));

        try {
            app(ManifestacaoNfse::class)->confirmar($nfse);
            $this->fail('Deveria falhar com a rejeição da SEFIN.');
        } catch (ManifestacaoNfseException $e) {
            $this->assertSame('E0861: Evento conflitante', $e->getMessage());
        }

        $this->assertNull($nfse->fresh()->manifestacao);
    }

    public function test_erro_da_sefin_como_objeto_tambem_e_lido(): void
    {
        $unidade = $this->unidade();
        $this->sefin(400, json_encode(['erro' => ['codigo' => 'E1831', 'descricao' => 'NFS-e inexistente no ADN']]));

        $this->expectExceptionMessage('E1831: NFS-e inexistente no ADN');

        app(ManifestacaoNfse::class)->confirmar($this->nota($unidade));
    }

    public function test_so_o_tomador_manifesta(): void
    {
        $unidade = $this->unidade();
        $this->sefin(201, $this->sucesso());

        $this->expectExceptionMessage('tomadora');

        app(ManifestacaoNfse::class)->confirmar($this->nota($unidade, ['papel' => Nfse::PAPEL_PRESTADOR]));
    }

    public function test_nota_cancelada_nao_e_manifestada(): void
    {
        $unidade = $this->unidade();
        $this->sefin(201, $this->sucesso());

        $this->expectExceptionMessage('cancelada ou substituída');

        app(ManifestacaoNfse::class)->confirmar($this->nota($unidade, ['situacao' => Nfse::SITUACAO_CANCELADA]));
    }

    public function test_nota_ja_manifestada_nao_e_enviada_de_novo(): void
    {
        $unidade = $this->unidade();
        $this->sefin(201, $this->sucesso());

        try {
            app(ManifestacaoNfse::class)->confirmar($this->nota($unidade, ['manifestacao' => Nfse::MANIFESTACAO_CONFIRMADA]));
            $this->fail('Deveria recusar.');
        } catch (ManifestacaoNfseException $e) {
            $this->assertStringContainsString('já foi manifestada', $e->getMessage());
        }

        $this->assertSame([], $this->enviados);
    }

    public function test_motivo_outros_exige_descricao_e_tamanhos_sao_validados(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());
        $servico = app(ManifestacaoNfse::class);

        foreach ([[9, null], [9, 'curta'], [2, 'curta'], [2, str_repeat('x', 256)], [7, null]] as [$motivo, $descricao]) {
            try {
                $servico->rejeitar($nfse, $motivo, $descricao);
                $this->fail("Deveria recusar motivo {$motivo}.");
            } catch (ManifestacaoNfseException) {
                // esperado
            }
        }

        $this->assertSame([], $this->enviados, 'Nenhum pedido vai à SEFIN com dados inválidos.');
    }

    public function test_falha_de_conexao_nao_marca_a_nota(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);

        $this->app->instance(SefinClient::class, new class implements SefinClient
        {
            public function registrarEvento(Unidade $unidade, string $chave, string $pedidoXmlAssinado): RespostaSefin
            {
                throw new \RuntimeException('Falha de conexão com a NFS-e Nacional: timeout.');
            }
        });

        try {
            app(ManifestacaoNfse::class)->confirmar($nfse);
            $this->fail('Deveria propagar a falha de conexão.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }

        $this->assertNull($nfse->fresh()->manifestacao);
    }

    // ------------------------------------------------------------------ API

    private function usuario(Unidade $unidade, string $papel = 'operador', bool $mfaRecente = true): User
    {
        $u = User::factory()->comTenant($unidade->tenant_id)->create();
        $u->assignRole($papel);
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => $mfaRecente ? now() : now()->subHour(),
        ])->save();

        return $u;
    }

    public function test_api_confirma_e_audita(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());

        $resp = $this->actingAs($this->usuario($unidade))
            ->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $unidade->id, 'acao' => 'confirmar']);

        $resp->assertOk()->assertJsonPath('data.manifestacao', 'confirmada');
        $this->assertDatabaseHas('audit_logs', ['acao' => 'nfse.manifestacao.registrada']);
    }

    public function test_api_exige_mfa_recente(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());

        $this->actingAs($this->usuario($unidade, mfaRecente: false))
            ->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $unidade->id, 'acao' => 'confirmar'])
            ->assertStatus(403)
            ->assertJsonPath('mfa_step_up_required', true);

        $this->assertSame([], $this->enviados);
    }

    public function test_api_consulta_nao_manifesta(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());

        $this->actingAs($this->usuario($unidade, 'consulta'))
            ->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $unidade->id, 'acao' => 'confirmar'])
            ->assertForbidden();

        $this->assertSame([], $this->enviados);
    }

    public function test_api_rejeicao_valida_motivo(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(201, $this->sucesso());
        $user = $this->actingAs($this->usuario($unidade));

        $user->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $unidade->id, 'acao' => 'rejeitar'])
            ->assertStatus(422)->assertJsonValidationErrors('motivo');

        $user->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $unidade->id, 'acao' => 'rejeitar', 'motivo' => 9])
            ->assertStatus(422)->assertJsonValidationErrors('descricao');

        $this->assertSame([], $this->enviados);
    }

    public function test_api_erro_da_sefin_volta_como_422_com_a_mensagem(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $this->sefin(400, json_encode(['erro' => [['codigo' => 'E0861', 'descricao' => 'Evento conflitante']]]));

        $this->actingAs($this->usuario($unidade))
            ->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $unidade->id, 'acao' => 'confirmar'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'E0861: Evento conflitante');

        $this->assertDatabaseHas('audit_logs', ['acao' => 'nfse.manifestacao.recusada']);
    }

    public function test_api_nota_de_outro_tenant_e_404(): void
    {
        $unidade = $this->unidade();
        $outra = $this->unidade();
        $nfse = $this->nota($outra);
        $this->sefin(201, $this->sucesso());

        $this->actingAs($this->usuario($unidade))
            ->postJson("/api/nfses/{$nfse->chave}/manifestacao", ['unidade_id' => $outra->id, 'acao' => 'confirmar'])
            ->assertNotFound();

        $this->assertSame([], $this->enviados);
    }

    public function test_detalhe_informa_se_pode_manifestar(): void
    {
        $unidade = $this->unidade();
        $nfse = $this->nota($unidade);
        $manifestada = $this->nota($unidade, [
            'chave' => $this->chaveNfse('99888777000166', 8),
            'manifestacao' => Nfse::MANIFESTACAO_CONFIRMADA,
        ]);
        $prestada = $this->nota($unidade, [
            'chave' => $this->chaveNfse('99888777000166', 9),
            'papel' => Nfse::PAPEL_PRESTADOR,
        ]);

        $operador = $this->actingAs($this->usuario($unidade));

        $operador->getJson("/api/nfses/{$nfse->chave}?unidade_id={$unidade->id}")->assertJsonPath('data.pode_manifestar', true);
        $operador->getJson("/api/nfses/{$manifestada->chave}?unidade_id={$unidade->id}")->assertJsonPath('data.pode_manifestar', false);
        $operador->getJson("/api/nfses/{$prestada->chave}?unidade_id={$unidade->id}")->assertJsonPath('data.pode_manifestar', false);

        $this->actingAs($this->usuario($unidade, 'consulta'))
            ->getJson("/api/nfses/{$nfse->chave}?unidade_id={$unidade->id}")
            ->assertJsonPath('data.pode_manifestar', false);
    }
}

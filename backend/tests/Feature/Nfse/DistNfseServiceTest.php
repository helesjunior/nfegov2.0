<?php

namespace Tests\Feature\Nfse;

use App\Domain\Nfse\AdnClient;
use App\Domain\Nfse\AdnGuard;
use App\Domain\Nfse\DistNfseService;
use App\Domain\Nfse\RespostaAdn;
use App\Domain\Sefaz\SefazGuard;
use App\Models\Nfse;
use App\Models\NfseEvento;
use App\Models\NfseNsu;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Laço de consumo da fila de NFS-e do ADN: cursor NSU, fim da fila por 404,
 * rejeições, documentos corrompidos, teto por execução e freio próprio.
 */
class DistNfseServiceTest extends TestCase
{
    use NfseXml;
    use RefreshDatabase;

    /** @var array<int, int> NSUs pedidos ao fake, na ordem. */
    private array $pedidos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['nfegov.adn.sleep_segundos' => 0]);
    }

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000181']);
    }

    /**
     * Fake que devolve as respostas na ordem e registra os NSUs pedidos.
     *
     * @param  array<int, array{0:int, 1:string}|\Throwable>  $respostas
     */
    private function fake(array $respostas): void
    {
        $teste = $this;

        $this->app->instance(AdnClient::class, new class($respostas, $teste) implements AdnClient
        {
            public function __construct(private array $respostas, private DistNfseServiceTest $teste) {}

            public function distribuicao(Unidade $unidade, int $ultimoNsu): RespostaAdn
            {
                $this->teste->registrarPedido($ultimoNsu);
                $proxima = array_shift($this->respostas);

                if ($proxima instanceof \Throwable) {
                    throw $proxima;
                }

                return RespostaAdn::deCorpo($proxima[0], $proxima[1]);
            }

            public function porChave(Unidade $unidade, string $chave): RespostaAdn
            {
                throw new RuntimeException('não usado');
            }
        });
    }

    public function registrarPedido(int $nsu): void
    {
        $this->pedidos[] = $nsu;
    }

    private function novaExecucao(Unidade $unidade): SyncRun
    {
        return SyncRun::create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'tipo' => SyncRun::TIPO_NFSE,
            'iniciado_em' => now(),
            'status' => SyncRun::STATUS_EM_ANDAMENTO,
        ]);
    }

    /**
     * Lote cheio (50 NFS-e) a partir do NSU informado.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loteCheio(int $primeiroNsu): array
    {
        $itens = [];
        for ($i = 0; $i < 50; $i++) {
            $nsu = $primeiroNsu + $i;
            $chave = $this->chaveNfse(numero: $nsu);
            $itens[] = $this->itemLote($nsu, $chave, $this->xmlNfse($chave));
        }

        return $itens;
    }

    public function test_drena_lotes_ate_a_fila_vazia_usando_o_ultimo_nsu_recebido(): void
    {
        $unidade = $this->unidade();
        $this->fake([
            [200, $this->corpoAdn($this->loteCheio(1))],
            [200, $this->corpoAdn($this->loteCheio(51))],
            [404, $this->corpoFilaVazia()],
        ]);

        $run = $this->novaExecucao($unidade);
        app(DistNfseService::class)->sync($unidade, $run);

        // Semântica do ultNSU: o próximo pedido usa o último NSU recebido.
        $this->assertSame([0, 50, 100], $this->pedidos);
        $this->assertSame(100, Nfse::count());

        $run->refresh();
        $this->assertSame(SyncRun::STATUS_SUCESSO, $run->status);
        $this->assertSame(100, (int) $run->docs_count);
        $this->assertSame(100, (int) $run->nsu_final);

        $cursor = NfseNsu::where('unidade_id', $unidade->id)->first();
        $this->assertSame(100, $cursor->ultimo_nsu);
        $this->assertNotNull($cursor->historico_concluido_em);
    }

    public function test_lote_incompleto_encerra_sem_nova_chamada(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $this->fake([
            [200, $this->corpoAdn([$this->itemLote(7, $chave, $this->xmlNfse($chave))])],
        ]);

        app(DistNfseService::class)->sync($unidade, $this->novaExecucao($unidade));

        $this->assertSame([0], $this->pedidos);
        $this->assertSame(7, NfseNsu::where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    public function test_retoma_do_cursor_salvo(): void
    {
        $unidade = $this->unidade();
        NfseNsu::create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id, 'ultimo_nsu' => 321]);
        $this->fake([[404, $this->corpoFilaVazia()]]);

        $run = $this->novaExecucao($unidade);
        app(DistNfseService::class)->sync($unidade, $run);

        $this->assertSame([321], $this->pedidos);
        $this->assertSame(SyncRun::STATUS_SUCESSO, $run->fresh()->status);
    }

    public function test_rejeicao_registra_erro_e_nao_move_o_cursor(): void
    {
        $unidade = $this->unidade();
        $this->fake([
            [400, $this->corpoAdn([], 'REJEICAO', [['Codigo' => 'E2245', 'Descricao' => 'Não há procuração.']])],
        ]);

        $run = $this->novaExecucao($unidade);
        app(DistNfseService::class)->sync($unidade, $run);

        $run->refresh();
        $this->assertSame(SyncRun::STATUS_ERRO, $run->status);
        $this->assertStringContainsString('E2245', $run->erro);
        $this->assertSame(0, NfseNsu::where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    public function test_http_400_com_status_de_sucesso_tambem_e_rejeicao(): void
    {
        $unidade = $this->unidade();
        $this->fake([
            [400, $this->corpoAdn([], 'DOCUMENTOS_LOCALIZADOS', [['Codigo' => 'E2243', 'Descricao' => 'CNPJ base diferente.']])],
        ]);

        $run = $this->novaExecucao($unidade);
        app(DistNfseService::class)->sync($unidade, $run);

        $this->assertSame(SyncRun::STATUS_ERRO, $run->fresh()->status);
    }

    public function test_documento_corrompido_vira_aviso_e_nao_trava_a_fila(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse(numero: 2);
        $this->fake([[200, $this->corpoAdn([
            ['NSU' => 1, 'ChaveAcesso' => $this->chaveNfse(numero: 1), 'TipoDocumento' => 'NFSE', 'ArquivoXml' => 'nao-e-gzip'],
            $this->itemLote(2, $chave, $this->xmlNfse($chave)),
        ])]]);

        $run = $this->novaExecucao($unidade);
        app(DistNfseService::class)->sync($unidade, $run);

        $run->refresh();
        $this->assertSame(SyncRun::STATUS_SUCESSO, $run->status);
        $this->assertStringContainsString('1 documento(s) ignorado(s)', $run->erro);
        $this->assertSame(1, Nfse::count());
        $this->assertSame(2, NfseNsu::where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    public function test_teto_de_documentos_para_e_a_proxima_execucao_retoma(): void
    {
        config(['nfegov.adn.max_docs' => 30]);
        $unidade = $this->unidade();
        $this->fake([[200, $this->corpoAdn($this->loteCheio(1))]]);

        app(DistNfseService::class)->sync($unidade, $this->novaExecucao($unidade));

        $this->assertSame(30, Nfse::count());
        $cursor = NfseNsu::where('unidade_id', $unidade->id)->first();
        $this->assertSame(30, $cursor->ultimo_nsu);
        $this->assertNull($cursor->historico_concluido_em);
    }

    public function test_http_429_abre_o_freio_do_adn_sem_afetar_o_da_sefaz(): void
    {
        $unidade = $this->unidade();
        $this->fake([[429, '{"title":"Too Many Requests"}']]);

        $run = $this->novaExecucao($unidade);
        app(DistNfseService::class)->sync($unidade, $run);

        $this->assertSame(SyncRun::STATUS_BLOQUEADO, $run->fresh()->status);
        $this->assertTrue(app(AdnGuard::class)->isOpen());
        $this->assertFalse(app(SefazGuard::class)->isOpen());
    }

    public function test_falha_de_conexao_registra_erro_e_preserva_o_cursor(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $itens = $this->loteCheio(1);
        $this->fake([
            [200, $this->corpoAdn($itens)],
            new RuntimeException('Falha de conexão com o ADN: timeout.'),
        ]);

        $run = $this->novaExecucao($unidade);

        try {
            app(DistNfseService::class)->sync($unidade, $run);
            $this->fail('Deveria propagar a falha de conexão.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('timeout', $e->getMessage());
        }

        $this->assertSame(SyncRun::STATUS_ERRO, $run->fresh()->status);
        $this->assertSame(50, NfseNsu::where('unidade_id', $unidade->id)->value('ultimo_nsu'));
    }

    public function test_cancelamento_na_fila_marca_a_nota_como_cancelada(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $this->fake([[200, $this->corpoAdn([
            $this->itemLote(1, $chave, $this->xmlNfse($chave)),
            $this->itemLote(2, $chave, $this->xmlCancelamento($chave), 'EVENTO', 'CANCELAMENTO'),
        ])]]);

        app(DistNfseService::class)->sync($unidade, $this->novaExecucao($unidade));

        $this->assertSame(Nfse::SITUACAO_CANCELADA, Nfse::first()->situacao);
        $this->assertSame(1, NfseEvento::count());
    }
}

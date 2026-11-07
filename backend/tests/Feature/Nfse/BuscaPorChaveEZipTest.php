<?php

namespace Tests\Feature\Nfse;

use App\Domain\Nfse\AdnClient;
use App\Domain\Nfse\AdnGuard;
use App\Domain\Nfse\NfseQuery;
use App\Domain\Nfse\RespostaAdn;
use App\Jobs\ExportNfseXmlJob;
use App\Jobs\RecuperarNfseJob;
use App\Models\Certificate;
use App\Models\Nfse;
use App\Models\NfseEvento;
use App\Models\NfseNsu;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use Tests\TestCase;

/**
 * Busca manual de NFS-e por chave e exportação ZIP dos XMLs.
 */
class BuscaPorChaveEZipTest extends TestCase
{
    use NfseXml;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(RolesSeeder::class);
    }

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000181']);
    }

    private function usuario(Unidade $unidade, string $papel = 'operador'): User
    {
        $u = User::factory()->comTenant($unidade->tenant_id)->create();
        $u->assignRole($papel);

        return $u;
    }

    private function fakeAdn(int $http, string $corpo): void
    {
        $this->app->instance(AdnClient::class, new class($http, $corpo) implements AdnClient
        {
            public function __construct(private int $http, private string $corpo) {}

            public function distribuicao(Unidade $unidade, int $ultimoNsu): RespostaAdn
            {
                throw new \RuntimeException('não usado');
            }

            public function porChave(Unidade $unidade, string $chave): RespostaAdn
            {
                return RespostaAdn::deCorpo($this->http, $this->corpo);
            }
        });
    }

    private function mensagemDaNotificacao(User $user): string
    {
        return (string) $user->notifications()->latest()->first()?->data['mensagem'];
    }

    // ------------------------------------------------------- busca por chave

    public function test_api_agenda_a_busca_e_recusa_pedido_repetido(): void
    {
        Queue::fake();
        Cache::flush();
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $user = $this->actingAs($this->usuario($unidade));

        $user->postJson('/api/nfses/recuperar', ['unidade_id' => $unidade->id, 'chave' => $chave])->assertStatus(202);
        Queue::assertPushed(RecuperarNfseJob::class, fn ($j) => $j->chave === $chave && $j->queue === 'adn');

        $user->postJson('/api/nfses/recuperar', ['unidade_id' => $unidade->id, 'chave' => $chave])->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['acao' => 'nfse.recuperacao.solicitada']);
    }

    public function test_api_valida_chave_e_permissao_e_escopo(): void
    {
        Queue::fake();
        $unidade = $this->unidade();
        $outra = $this->unidade();

        $this->actingAs($this->usuario($unidade))
            ->postJson('/api/nfses/recuperar', ['unidade_id' => $unidade->id, 'chave' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('chave');

        $this->actingAs($this->usuario($unidade, 'consulta'))
            ->postJson('/api/nfses/recuperar', ['unidade_id' => $unidade->id, 'chave' => $this->chaveNfse()])
            ->assertForbidden();

        $this->actingAs($this->usuario($unidade))
            ->postJson('/api/nfses/recuperar', ['unidade_id' => $outra->id, 'chave' => $this->chaveNfse()])
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_job_importa_nota_e_eventos_sem_mexer_no_cursor(): void
    {
        $unidade = $this->unidade();
        $user = $this->usuario($unidade);
        NfseNsu::create(['tenant_id' => $unidade->tenant_id, 'unidade_id' => $unidade->id, 'ultimo_nsu' => 500]);
        $chave = $this->chaveNfse();
        $this->fakeAdn(200, $this->corpoAdn([
            $this->itemLote(5, $chave, $this->xmlNfse($chave)),
            $this->itemLote(9, $chave, $this->xmlCancelamento($chave), 'EVENTO', 'CANCELAMENTO'),
        ]));

        (new RecuperarNfseJob($unidade->id, $user->id, $chave))
            ->handle(app(AdnClient::class), app(AdnGuard::class), app(\App\Domain\Nfse\NfseDocumentoProcessor::class));

        $nota = Nfse::where('unidade_id', $unidade->id)->first();
        $this->assertSame($chave, $nota->chave);
        $this->assertSame(Nfse::SITUACAO_CANCELADA, $nota->situacao);
        $this->assertSame(1, NfseEvento::count());
        $this->assertSame(500, NfseNsu::where('unidade_id', $unidade->id)->value('ultimo_nsu'));
        $this->assertStringContainsString('disponível para consulta', $this->mensagemDaNotificacao($user));
    }

    public function test_job_avisa_quando_a_chave_nao_referencia_a_unidade(): void
    {
        $unidade = $this->unidade();
        $user = $this->usuario($unidade);
        $this->fakeAdn(404, $this->corpoAdn([], 'NENHUM_DOCUMENTO_LOCALIZADO', [[
            'Codigo' => 'E2241',
            'Descricao' => 'Chave de acesso da NFS-e não referencia o Contribuinte solicitante e por isso não pode ser compartilhado.',
        ]]));

        (new RecuperarNfseJob($unidade->id, $user->id, $this->chaveNfse()))
            ->handle(app(AdnClient::class), app(AdnGuard::class), app(\App\Domain\Nfse\NfseDocumentoProcessor::class));

        $msg = $this->mensagemDaNotificacao($user);
        $this->assertStringContainsString('não encontrou a nota', $msg);
        $this->assertStringContainsString('E2241', $msg);
        $this->assertSame(0, Nfse::count());
    }

    public function test_job_com_freio_aberto_nao_chama_o_adn_e_avisa(): void
    {
        $unidade = $this->unidade();
        $user = $this->usuario($unidade);
        app(AdnGuard::class)->tripCircuitBreaker($unidade);
        $cliente = \Mockery::mock(AdnClient::class);
        $cliente->shouldNotReceive('porChave');
        $this->app->instance(AdnClient::class, $cliente);

        (new RecuperarNfseJob($unidade->id, $user->id, $this->chaveNfse()))
            ->handle($cliente, app(AdnGuard::class), app(\App\Domain\Nfse\NfseDocumentoProcessor::class));

        $this->assertStringContainsString('temporariamente indisponível', $this->mensagemDaNotificacao($user));
    }

    public function test_job_libera_a_trava_de_idempotencia_ao_terminar(): void
    {
        $unidade = $this->unidade();
        $user = $this->usuario($unidade);
        $chave = $this->chaveNfse();
        $this->fakeAdn(404, $this->corpoFilaVazia());
        $job = new RecuperarNfseJob($unidade->id, $user->id, $chave);
        Cache::put($job->chaveIdempotencia(), true, 300);

        $job->handle(app(AdnClient::class), app(AdnGuard::class), app(\App\Domain\Nfse\NfseDocumentoProcessor::class));

        $this->assertFalse(Cache::has($job->chaveIdempotencia()));
    }

    // ----------------------------------------------------------------- ZIP

    public function test_zip_tem_uma_pasta_por_unidade_e_os_eventos(): void
    {
        $unidade = $this->unidade();
        $user = $this->usuario($unidade);
        $importer = app(\App\Domain\Nfse\NfseImporter::class);
        $chave1 = $this->chaveNfse(numero: 1);
        $chave2 = $this->chaveNfse(numero: 2);

        $importer->importarNfse($unidade, $this->xmlNfse($chave1), 1);
        $importer->importarNfse($unidade, $this->xmlNfse($chave2), 2);
        $importer->importarEvento($unidade, $this->xmlCancelamento($chave1), 3);
        // Sem XML no disco: não entra no ZIP.
        Nfse::factory()->create(['unidade_id' => $unidade->id, 'xml_path' => null]);

        (new ExportNfseXmlJob($unidade->tenant_id, $user->id, ['situacao' => 'cancelada']))->handle(app(NfseQuery::class));
        $notificacao = $user->notifications()->first();
        $this->assertSame(1, $notificacao->data['total']);

        (new ExportNfseXmlJob($unidade->tenant_id, $user->id, []))->handle(app(NfseQuery::class));
        $notificacao = $user->notifications()->latest()->first();
        $this->assertSame(2, $notificacao->data['total']);
        $this->assertSame('XMLs de NFS-e', $notificacao->data['documento']);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($notificacao->data['caminho']));
        $nomes = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nomes[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($nomes);

        $this->assertSame([
            "11222333000181/{$chave1}.xml",
            "11222333000181/{$chave2}.xml",
            "11222333000181/eventos/{$chave1}-3.xml",
        ], $nomes);
    }

    public function test_api_do_zip_agenda_job_e_exige_exportacao(): void
    {
        Queue::fake();
        $unidade = $this->unidade();

        $this->actingAs($this->usuario($unidade))
            ->postJson('/api/nfses/export-xml', ['papel' => 'tomador'])
            ->assertStatus(202);
        Queue::assertPushed(ExportNfseXmlJob::class, fn ($j) => $j->filtros === ['papel' => 'tomador']);

        $this->actingAs($this->usuario($unidade, 'consulta'))
            ->postJson('/api/nfses/export-xml', [])
            ->assertForbidden();
    }

    public function test_importar_evento_de_manifestacao_do_tomador_atualiza_a_nota(): void
    {
        $unidade = $this->unidade();
        $chave = $this->chaveNfse();
        $importer = app(\App\Domain\Nfse\NfseImporter::class);
        $importer->importarNfse($unidade, $this->xmlNfse($chave), 1);

        $importer->importarEvento($unidade, str_replace('e101101', 'e203202', $this->xmlCancelamento($chave)), 2, 'CONFIRMACAO_TOMADOR');

        $nota = Nfse::first();
        $this->assertSame(Nfse::MANIFESTACAO_CONFIRMADA, $nota->manifestacao);
        $this->assertNotNull($nota->manifestada_em);
        $this->assertSame(Nfse::SITUACAO_NORMAL, $nota->situacao, 'A confirmação não cancela a nota.');
    }

    public function test_sem_certificado_a_unidade_nao_entra_na_busca_do_job(): void
    {
        // O certificado é checado pelo cliente (MtlsCurl); aqui o cliente real falha de forma clara.
        $unidade = $this->unidade();
        $this->expectExceptionMessage('não possui certificado ativo');

        app(\App\Domain\Nfse\MtlsCurl::class)->certificado($unidade);
    }
}

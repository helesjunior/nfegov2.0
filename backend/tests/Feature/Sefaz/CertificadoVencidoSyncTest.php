<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\SefazClient;
use App\Domain\Sefaz\ToolsFactory;
use App\Jobs\DispatchSyncNfeJob;
use App\Jobs\SyncNfeJob;
use App\Models\Certificate;
use App\Models\Nfe;
use App\Models\SyncRun;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regra: SÓ sincroniza quando o certificado e-CNPJ está VIGENTE.
 *
 * Consultar a SEFAZ com um certificado vencido é inútil e prejudicial: a
 * requisição é enviada, consome/avança NSU e a SEFAZ rejeita — exatamente o
 * caminho que leva ao bloqueio por "consumo indevido" (cStat 656).
 *
 * A regra é defendida em TRÊS camadas:
 *   1. `DispatchSyncNfeJob`  — nem enfileira unidade sem certificado vigente.
 *   2. `SyncNfeJob`          — revalida (a fila pode envelhecer) e marca o
 *                              `SyncRun` como bloqueado SEM tocar a SEFAZ.
 *   3. `ToolsFactory::make`  — última linha de defesa: lança exceção clara,
 *                              cobrindo chamadas manuais/fora dos jobs.
 */
class CertificadoVencidoSyncTest extends TestCase
{
    use RefreshDatabase;

    private function unidadeComCertificado(array $cert = []): Unidade
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);

        Certificate::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
        ], $cert));

        return $unidade;
    }

    // -----------------------------------------------------------------------
    // Camada 1 — DispatchSyncNfeJob não enfileira unidade com cert. vencido
    // -----------------------------------------------------------------------

    public function test_dispatch_nao_enfileira_unidade_com_certificado_vencido(): void
    {
        Queue::fake();

        $this->unidadeComCertificado(['valido_ate' => now()->subDay()]);

        app(DispatchSyncNfeJob::class)->handle(app(\App\Domain\Sefaz\SefazGuard::class));

        Queue::assertNotPushed(SyncNfeJob::class);
    }

    public function test_dispatch_enfileira_unidade_com_certificado_vigente(): void
    {
        Queue::fake();

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->addDays(30)]);

        // Executa o job de distribuição diretamente (caminho do scheduler).
        app(DispatchSyncNfeJob::class)->handle(app(\App\Domain\Sefaz\SefazGuard::class));

        Queue::assertPushed(SyncNfeJob::class, fn ($job) => $job->unidadeId === $unidade->id);
    }

    public function test_dispatch_nao_enfileira_unidade_sem_certificado(): void
    {
        Queue::fake();

        $tenant = Tenant::factory()->create();
        Unidade::factory()->create(['tenant_id' => $tenant->id, 'ativo' => true]);

        app(DispatchSyncNfeJob::class)->handle(app(\App\Domain\Sefaz\SefazGuard::class));

        Queue::assertNotPushed(SyncNfeJob::class);
    }

    /**
     * Unidade com certificado ATIVO vigente deve entrar, mesmo existindo
     * certificados INATIVOS mais novos.
     *
     * Regressão: filtrar por validade sobre o `latestOfMany` da relação
     * `certificate()` escolheria um certificado inativo mais recente e
     * excluiria indevidamente a unidade — interrompendo a recepção de NF-e
     * de quem está em dia.
     */
    public function test_dispatch_enfileira_quando_ha_certificado_inativo_mais_novo(): void
    {
        Queue::fake();

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->addDays(30)]);

        // Um certificado ANTIGO inativo e um NOVO inativo (id maior) não podem
        // esconder o vigente ativo.
        Certificate::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subYear(),
            'ativo' => false,
        ]);
        Certificate::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subDay(),
            'ativo' => false,
        ]);

        app(DispatchSyncNfeJob::class)->handle(app(\App\Domain\Sefaz\SefazGuard::class));

        Queue::assertPushed(SyncNfeJob::class, fn ($job) => $job->unidadeId === $unidade->id);
    }

    // -----------------------------------------------------------------------
    // Camada 2 — SyncNfeJob revalida e NÃO chama a SEFAZ
    // -----------------------------------------------------------------------

    public function test_sync_job_nao_chama_sefaz_com_certificado_vencido(): void
    {
        Storage::fake('local');

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->subDay()]);

        // Registra qualquer chamada ao cliente; a asserção é que NENHUMA ocorra.
        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->andReturn(
            '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>137</cStat><xMotivo>Ok</xMotivo><ultNSU>0</ultNSU><maxNSU>0</maxNSU>'
            .'</retDistDFeInt>'
        );
        app()->instance(SefazClient::class, $client);

        SyncNfeJob::dispatchSync($unidade->id);

        // A SEFAZ JAMAIS deve ser consultada com certificado vencido.
        $client->shouldNotHaveReceived('distDFe');

        // Nenhuma NF-e importada e nenhum NSU consumido.
        $this->assertSame(0, Nfe::withoutGlobalScopes()->count());

        // A execução fica REGISTRADA como bloqueada (não como erro), para
        // que a operação veja o motivo sem poluir o histórico de falhas.
        $run = SyncRun::withoutGlobalScopes()->where('unidade_id', $unidade->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(SyncRun::STATUS_BLOQUEADO, $run->status);
    }

    public function test_sync_job_processa_quando_certificado_vigente(): void
    {
        Storage::fake('local');

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->addDays(30)]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn(
            '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>137</cStat><xMotivo>Ok</xMotivo><ultNSU>0</ultNSU><maxNSU>0</maxNSU>'
            .'</retDistDFeInt>'
        );
        app()->instance(SefazClient::class, $client);

        SyncNfeJob::dispatchSync($unidade->id);

        $run = SyncRun::withoutGlobalScopes()->where('unidade_id', $unidade->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(SyncRun::STATUS_SUCESSO, $run->status);
    }

    /**
     * A revalidação da Camada 2 deve considerar o certificado ATIVO, não o de
     * maior id: um certificado inativo mais novo não pode bloquear o sync de
     * uma unidade cujo certificado ativo está vigente.
     */
    public function test_sync_job_processa_com_inativo_mais_novo_que_o_vigente(): void
    {
        Storage::fake('local');

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->addDays(30)]);

        Certificate::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subDay(),
            'ativo' => false,
        ]);

        $client = \Mockery::mock(SefazClient::class);
        $client->shouldReceive('distDFe')->once()->andReturn(
            '<?xml version="1.0"?><retDistDFeInt xmlns="http://www.portalfiscal.inf.br/nfe">'
            .'<cStat>137</cStat><xMotivo>Ok</xMotivo><ultNSU>0</ultNSU><maxNSU>0</maxNSU>'
            .'</retDistDFeInt>'
        );
        app()->instance(SefazClient::class, $client);

        SyncNfeJob::dispatchSync($unidade->id);

        $run = SyncRun::withoutGlobalScopes()->where('unidade_id', $unidade->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame(SyncRun::STATUS_SUCESSO, $run->status);
    }

    // -----------------------------------------------------------------------
    // Camada 3 — ToolsFactory é a última linha de defesa
    // -----------------------------------------------------------------------

    public function test_tools_factory_rejeita_certificado_vencido(): void
    {
        Storage::fake('local');

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->subDay()]);

        $this->expectException(\RuntimeException::class);

        app(ToolsFactory::class)->make($unidade);
    }

    /**
     * A Camada 3 também deve escolher o certificado ATIVO — não o de maior id.
     *
     * Com um ativo vigente e um inativo mais novo, a montagem deve seguir até a
     * leitura do arquivo do PFX (erro "não encontrado") e NÃO parar em "vencido".
     */
    public function test_tools_factory_escolhe_certificado_ativo_nao_o_mais_novo(): void
    {
        Storage::fake('local');

        $unidade = $this->unidadeComCertificado(['valido_ate' => now()->addDays(30)]);

        Certificate::factory()->create([
            'tenant_id' => $unidade->tenant_id,
            'unidade_id' => $unidade->id,
            'valido_ate' => now()->subDay(),
            'ativo' => false,
        ]);

        try {
            app(ToolsFactory::class)->make($unidade);
            $this->fail('Esperava falha ao ler o PFX inexistente.');
        } catch (\RuntimeException $e) {
            // O PFX fake não existe; o erro deve ser de ARQUIVO, nunca "vencido".
            $this->assertStringNotContainsString('vencido', $e->getMessage());
        }
    }
}
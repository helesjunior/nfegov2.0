<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ExportPronto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Fluxo de entrega das exportações assíncronas.
 *
 * O job grava o arquivo no disco privado e notifica por `ExportPronto`. Aqui
 * cobrimos o trecho que faltava: listar as notificações do usuário e baixar o
 * arquivo por uma rota autenticada e assinada.
 */
class ExportDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesSeeder::class);

        $this->tenant = Tenant::create(['nome' => 'AGU', 'cnpj' => '00000000000191', 'ativo' => true]);

        $this->operador = User::create([
            'name' => 'Operador',
            'email' => 'operador@agu.gov.br',
            'password' => bcrypt('senha123'),
            'tenant_id' => $this->tenant->id,
        ]);
        $this->operador->assignRole('operador');
        $this->operador->tenants()->syncWithoutDetaching([$this->tenant->id]);
    }

    private function ativarTenant(): void
    {
        \App\Support\TenantContext::set($this->tenant->id);
    }

    public function test_lista_apenas_notificacoes_do_usuario(): void
    {
        $outro = User::create([
            'name' => 'Outro',
            'email' => 'outro@agu.gov.br',
            'password' => bcrypt('senha123'),
            'tenant_id' => $this->tenant->id,
        ]);

        $this->operador->notify(new ExportPronto('exports/'.$this->tenant->id.'/a.csv', 'csv', 3));
        $outro->notify(new ExportPronto('exports/'.$this->tenant->id.'/b.csv', 'csv', 9));

        $this->actingAs($this->operador)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.dados.formato', 'csv')
            ->assertJsonPath('data.0.dados.total', 3);
    }

    public function test_baixa_arquivo_de_exportacao_com_link_assinado(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put("exports/{$this->tenant->id}/relatorio.csv", 'Chave,Numero'.PHP_EOL);

        $url = URL::temporarySignedRoute('api.exports.download', now()->addMinutes(10), [
            'arquivo' => 'relatorio.csv',
        ]);

        $resp = $this->actingAs($this->operador)->get($url);

        $resp->assertOk();
        $this->assertStringContainsString('attachment', $resp->headers->get('content-disposition'));
        $this->assertStringContainsString('Chave,Numero', $resp->streamedContent());
    }

    public function test_download_sem_assinatura_valida_e_rejeitado(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put("exports/{$this->tenant->id}/relatorio.csv", 'x');

        $this->actingAs($this->operador)
            ->get('/api/exports/relatorio.csv')
            ->assertForbidden();
    }

    public function test_nao_baixa_arquivo_de_outro_tenant(): void
    {
        Storage::fake('local');
        $outroTenant = Tenant::create(['nome' => 'OUTRO', 'cnpj' => '11111111111111', 'ativo' => true]);
        Storage::disk('local')->put("exports/{$outroTenant->id}/sigiloso.csv", 'x');

        $url = URL::temporarySignedRoute('api.exports.download', now()->addMinutes(10), [
            'arquivo' => 'sigiloso.csv',
        ]);

        $this->actingAs($this->operador)->get($url)->assertNotFound();
    }

    public function test_rejeita_nome_de_arquivo_com_travessia_de_diretorio(): void
    {
        Storage::fake('local');

        $url = URL::temporarySignedRoute('api.exports.download', now()->addMinutes(10), [
            'arquivo' => '..%2F.env',
        ]);

        $this->actingAs($this->operador)->get($url)->assertNotFound();
    }

    /**
     * Usuário com vínculo em duas entidades baixa a exportação da segunda
     * pelo link da notificação, mesmo com a primeira como `users.tenant_id`.
     */
    public function test_baixa_exportacao_de_outra_entidade_vinculada(): void
    {
        Storage::fake('local');
        $outra = Tenant::create(['nome' => 'OUTRA', 'cnpj' => '22222222222222', 'ativo' => true]);
        $this->operador->tenants()->syncWithoutDetaching([$outra->id]);

        Storage::disk('local')->put("exports/{$outra->id}/relatorio.csv", 'Chave,Numero'.PHP_EOL);
        $this->operador->notify(new ExportPronto("exports/{$outra->id}/relatorio.csv", 'csv', 1));

        $url = $this->actingAs($this->operador)
            ->getJson('/api/notifications')
            ->assertOk()
            ->json('data.0.url_download');

        $resp = $this->actingAs($this->operador)->get($url);

        $resp->assertOk();
        $this->assertStringContainsString('Chave,Numero', $resp->streamedContent());
    }

    public function test_link_assinado_de_entidade_sem_vinculo_retorna_404(): void
    {
        Storage::fake('local');
        $outroTenant = Tenant::create(['nome' => 'OUTRO', 'cnpj' => '11111111111111', 'ativo' => true]);
        Storage::disk('local')->put("exports/{$outroTenant->id}/sigiloso.csv", 'x');

        $url = URL::temporarySignedRoute('api.exports.download', now()->addMinutes(10), [
            'arquivo' => 'sigiloso.csv',
            'tenant' => $outroTenant->id,
        ]);

        $this->actingAs($this->operador)->get($url)->assertNotFound();
    }
}

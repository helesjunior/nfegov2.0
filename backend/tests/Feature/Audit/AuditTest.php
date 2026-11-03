<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Trilha de auditoria de ações sensíveis.
 *
 * Toda ação sensível (rotação de certificado, download de XML/DANFE) deve
 * deixar registro: quem, o quê, quando, de onde. O log nunca expõe segredo
 * (senha/PFX): apenas metadados.
 */
class AuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function adminComMfa(Tenant $tenant): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        return $u;
    }

    private function operador(Tenant $tenant): User
    {
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('operador');

        return $u;
    }

    private function pfx(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/cert_e_cnpj.pfx'),
            'certificado.pfx',
            'application/x-pkcs12',
            null,
            true,
        );
    }

    public function test_rotacao_de_certificado_gera_audit_log(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000181']);
        $admin = $this->adminComMfa($tenant);

        $this->actingAs($admin)
            ->postJson("/api/unidades/{$unidade->id}/certificado", [
                'arquivo' => $this->pfx(),
                'senha' => 'senha-pfx',
            ])
            ->assertCreated();

        $log = AuditLog::where('acao', 'certificado.rotacionado')->first();

        $this->assertNotNull($log);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('unidade', $log->entidade);
        $this->assertSame((string) $unidade->id, (string) $log->entidade_id);
        $this->assertSame('unidade', $log->contexto['entidade'] ?? null);
    }

    public function test_audit_log_nao_expoe_senha_nem_pfx(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000181']);
        $admin = $this->adminComMfa($tenant);

        $this->actingAs($admin)
            ->postJson("/api/unidades/{$unidade->id}/certificado", [
                'arquivo' => $this->pfx(),
                'senha' => 'senha-pfx',
            ])
            ->assertCreated();

        $log = AuditLog::where('acao', 'certificado.rotacionado')->first();
        $serializado = json_encode($log->contexto);

        $this->assertStringNotContainsString('senha-pfx', $serializado);
        $this->assertStringNotContainsString('BEGIN', $serializado);
    }

    public function test_download_de_xml_gera_audit_log(): void
    {
        Storage::fake('local');

        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $user = $this->operador($tenant);

        $nfe = Nfe::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'xml_path' => 'tenants/1/unidades/1/nfes/2026/10/'.$this->chave(),
        ]);
        Storage::disk('local')->put($nfe->xml_path, '<xml>ok</xml>');

        // O detalhe gera a URL assinada; o download efetivo é o que auditamos.
        $resp = $this->actingAs($user)->getJson('/api/nfes/'.$nfe->chave);
        $resp->assertOk();

        $this->actingAs($user)->get($resp->json('data.xml_url'))->assertOk();

        $log = AuditLog::where('acao', 'nfe.xml.baixado')->first();

        $this->assertNotNull($log);
        $this->assertSame($nfe->chave, $log->entidade_id);
        $this->assertSame($tenant->id, $log->tenant_id);
        $this->assertSame($user->id, $log->user_id);
    }

    public function test_criacao_de_unidade_gera_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->adminComMfa($tenant);

        $this->actingAs($admin)
            ->postJson('/api/unidades', [
                'nome' => 'Unidade Nova',
                'cnpj' => '11222333000181',
                'uf' => 'SP',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'acao' => 'unidade.criada',
            'user_id' => $admin->id,
        ]);
    }

    public function test_audit_registra_ip_e_user_agent(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->adminComMfa($tenant);

        $this->actingAs($admin)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeaders(['User-Agent' => 'TestAgent/1.0'])
            ->postJson('/api/unidades', [
                'nome' => 'Unidade IP',
                'cnpj' => '99888777000166',
                'uf' => 'SP',
            ])
            ->assertCreated();

        $log = AuditLog::where('acao', 'unidade.criada')->first();

        $this->assertNotNull($log);
        $this->assertSame('203.0.113.7', $log->ip);
        $this->assertSame('TestAgent/1.0', $log->user_agent);
    }

    private function chave(): string
    {
        return (string) fake()->unique()->numerify(str_repeat('#', 44));
    }
}
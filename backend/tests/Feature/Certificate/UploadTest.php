<?php

namespace Tests\Feature\Certificate;

use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Upload e rotação de certificado e-CNPJ.
 *
 * Regras de segurança centrais:
 * - O PFX e a senha NUNCA são devolvidos por nenhum endpoint.
 * - Rejeita senha inválida, CNPJ divergente da unidade e certificado vencido.
 * - Desativa o certificado anterior e mantém histórico.
 * - A rotação exige step-up (MFA recente).
 */
class UploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    /**
     * Prepara admin-tenant com MFA confirmado e janela recente (passa step-up).
     */
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

    private function pfx(string $arquivo = 'cert_e_cnpj.pfx', string $nome = 'certificado.pfx'): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/'.$arquivo),
            $nome,
            'application/x-pkcs12',
            null,
            true
        );
    }

    public function test_upload_valido_persiste_certificado_e_extrai_metadados(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->adminComMfa($tenant);
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181', // mesmo CNPJ da fixture
        ]);

        $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(),
            'senha' => 'senha-pfx',
        ])->assertCreated();

        $cert = Certificate::where('unidade_id', $unidade->id)->first();
        $this->assertNotNull($cert);
        $this->assertSame('11222333000181', $cert->cnpj);
        $this->assertTrue($cert->ativo);
        $this->assertNotNull($cert->valido_ate);
        $this->assertNotEmpty($cert->arquivo_path);
    }

    public function test_upload_substitui_certificado_anterior(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->adminComMfa($tenant);
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $antigo = Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'ativo' => true,
        ]);

        $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(),
            'senha' => 'senha-pfx',
        ])->assertCreated();

        $this->assertFalse($antigo->fresh()->ativo);
        $this->assertSame(1, Certificate::where('unidade_id', $unidade->id)->where('ativo', true)->count());
    }

    public function test_upload_rejeita_senha_invalida(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->adminComMfa($tenant);
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(),
            'senha' => 'senha-errada',
        ])->assertStatus(422);

        $this->assertSame(0, Certificate::where('unidade_id', $unidade->id)->count());
    }

    public function test_upload_rejeita_cnpj_divergente(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->adminComMfa($tenant);

        // Unidade com CNPJ diferente do contido no PFX.
        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '00000000000191',
        ]);

        $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(), // PFX tem 11222333000181
            'senha' => 'senha-pfx',
        ])->assertStatus(422);

        $this->assertSame(0, Certificate::where('unidade_id', $unidade->id)->count());
    }

    public function test_upload_rejeita_certificado_vencido(): void
    {
        $tenant = Tenant::factory()->create();

        // Avança o relógio 5 anos: o certificado (1 ano) estará vencido, e o
        // MFA do admin fica "recente" neste relógio (passa o step-up).
        Carbon::setTestNow(now()->addYears(5));

        try {
            $u = $this->adminComMfa($tenant);
            $unidade = Unidade::factory()->create([
                'tenant_id' => $tenant->id,
                'cnpj' => '11222333000181',
            ]);

            $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
                'arquivo' => $this->pfx(),
                'senha' => 'senha-pfx',
            ])->assertStatus(422);
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(0, Certificate::where('unidade_id', $unidade->id)->count());
    }

    public function test_upload_exige_step_up(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->adminComMfa($tenant);
        // Janela de MFA expirada.
        $u->forceFill(['mfa_recent_at' => now()->subHour()])->save();

        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(),
            'senha' => 'senha-pfx',
        ])->assertForbidden();

        $this->assertSame(0, Certificate::where('unidade_id', $unidade->id)->count());
    }

    public function test_get_devolve_apenas_metadados(): void
    {
        $tenant = Tenant::factory()->create();
        $u = $this->adminComMfa($tenant);
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        Certificate::factory()->create([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
            'cnpj' => $unidade->cnpj,
            'titular' => 'Empresa X',
            'ativo' => true,
        ]);

        $resp = $this->actingAs($u)->getJson("/api/unidades/{$unidade->id}/certificado")
            ->assertOk();

        $dados = $resp->json('certificado');
        $this->assertIsArray($dados);
        $this->assertArrayHasKey('cnpj', $dados);
        $this->assertArrayHasKey('titular', $dados);
        $this->assertArrayHasKey('valido_ate', $dados);

        // Nunca expõe segredo nem conteúdo do arquivo.
        $this->assertArrayNotHasKey('senha', $dados);
        $this->assertArrayNotHasKey('senha_encrypted', $dados);
        $this->assertArrayNotHasKey('arquivo_path', $dados);
        $this->assertStringNotContainsString('senha', $resp->getContent());
    }

    public function test_nao_acessa_certificado_de_unidade_de_outro_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $u = $this->adminComMfa($a);
        $unidadeB = Unidade::factory()->create(['tenant_id' => $b->id]);

        $this->actingAs($u)->getJson("/api/unidades/{$unidadeB->id}/certificado")
            ->assertNotFound();
    }

    public function test_operador_nao_pode_enviar_certificado(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->assignRole('operador');

        $unidade = Unidade::factory()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);

        $this->actingAs($u)->postJson("/api/unidades/{$unidade->id}/certificado", [
            'arquivo' => $this->pfx(),
            'senha' => 'senha-pfx',
        ])->assertForbidden();
    }
}
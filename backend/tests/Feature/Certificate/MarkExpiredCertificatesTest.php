<?php

namespace Tests\Feature\Certificate;

use App\Models\Certificate;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Marcação explícita de certificados e-CNPJ vencidos.
 *
 * O comando `certificate-expiry:mark` grava `expirado_em` nos certificados
 * ATIVOS que já venceram — sem desativá-los (`ativo` continua significando
 * "certificado corrente da unidade"). É idempotente: nunca reescreve um
 * carimbo já existente (o momento real do vencimento é preservado).
 *
 * Roda globalmente (sem contexto de tenant), como o `certificate-alerts:check`.
 */
class MarkExpiredCertificatesTest extends TestCase
{
    use RefreshDatabase;

    private function certificado(array $atributos = []): Certificate
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        return Certificate::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'unidade_id' => $unidade->id,
        ], $atributos));
    }

    public function test_marca_certificado_ativo_vencido(): void
    {
        $cert = $this->certificado([
            'ativo' => true,
            'valido_ate' => now()->subDay(),
            'expirado_em' => null,
        ]);

        $this->artisan('certificate-expiry:mark')->assertSuccessful();

        $this->assertNotNull($cert->fresh()->expirado_em);
    }

    public function test_nao_marca_certificado_vigente(): void
    {
        $cert = $this->certificado([
            'ativo' => true,
            'valido_ate' => now()->addDays(30),
            'expirado_em' => null,
        ]);

        $this->artisan('certificate-expiry:mark')->assertSuccessful();

        $this->assertNull($cert->fresh()->expirado_em);
    }

    public function test_nao_reaplica_carimbo_em_certificado_ja_marcado(): void
    {
        $original = now()->subDays(10)->startOfSecond();
        $cert = $this->certificado([
            'ativo' => true,
            'valido_ate' => now()->subDay(),
            'expirado_em' => $original,
        ]);

        $this->artisan('certificate-expiry:mark')->assertSuccessful();

        // O carimbo original é preservado (não é reescrito com now()).
        $this->assertSame(
            $original->toDateTimeString(),
            $cert->fresh()->expirado_em->toDateTimeString()
        );
    }

    public function test_nao_marca_certificado_inativo_vencido(): void
    {
        $cert = $this->certificado([
            'ativo' => false,
            'valido_ate' => now()->subDay(),
            'expirado_em' => null,
        ]);

        $this->artisan('certificate-expiry:mark')->assertSuccessful();

        $this->assertNull($cert->fresh()->expirado_em);
    }

    public function test_nao_desativa_o_certificado_vencido(): void
    {
        $cert = $this->certificado([
            'ativo' => true,
            'valido_ate' => now()->subDay(),
            'expirado_em' => null,
        ]);

        $this->artisan('certificate-expiry:mark')->assertSuccessful();

        // `ativo` permanece true: o vencido continua visível na operação.
        $this->assertTrue($cert->fresh()->ativo);
    }
}

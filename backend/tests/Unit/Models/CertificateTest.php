<?php

namespace Tests\Unit\Models;

use App\Models\Certificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Estado explícito de "certificado expirado".
 *
 * `expirado_em` é um carimbo gravado quando o certificado vence (comando
 * `certificate-expiry:mark`). NÃO substitui `ativo`: `ativo` continua
 * significando "é o certificado corrente da unidade" (rotação).
 */
class CertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_expirado_em_e_castado_para_datetime(): void
    {
        $cert = Certificate::factory()->create(['expirado_em' => now()->subDay()]);

        $this->assertInstanceOf(Carbon::class, $cert->expirado_em);
        $this->assertTrue($cert->foiExpirado());
    }

    public function test_foi_expirado_e_falso_por_padrao(): void
    {
        $cert = Certificate::factory()->create();

        $this->assertNull($cert->expirado_em);
        $this->assertFalse($cert->foiExpirado());
    }
}
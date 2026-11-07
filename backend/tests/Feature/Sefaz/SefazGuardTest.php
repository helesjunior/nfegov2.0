<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\SefazGuard;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tempo restante do freio global (usado para reagendar a recuperação).
 */
class SefazGuardTest extends TestCase
{
    use RefreshDatabase;

    private function unidade(): Unidade
    {
        return Unidade::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
    }

    public function test_sem_freio_o_tempo_restante_e_zero(): void
    {
        $this->assertSame(0, app(SefazGuard::class)->minutosRestantesDoFreio());
    }

    public function test_com_freio_aberto_o_tempo_restante_e_positivo(): void
    {
        $guard = app(SefazGuard::class);
        $guard->tripCircuitBreaker($this->unidade());

        $restante = $guard->minutosRestantesDoFreio();

        // Circuit breaker padrão = 60 min; usar intervalo amplo evita flake.
        $this->assertGreaterThan(0, $restante);
        $this->assertLessThanOrEqual(60, $restante);
    }
}

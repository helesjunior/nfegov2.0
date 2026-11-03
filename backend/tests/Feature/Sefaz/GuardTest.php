<?php

namespace Tests\Feature\Sefaz;

use App\Domain\Sefaz\SefazGuard;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Freio global anti-bloqueio da SEFAZ (IP compartilhado).
 *
 * Em instalação central, todos os tenants compartilham o mesmo IP de saída;
 * o limite da SEFAZ é por IP. Sem um freio global, um órgão pode derrubar o
 * acesso de todos os outros.
 *
 * Camadas:
 * - Rate limiter global (consultas/minuto).
 * - Circuit breaker (ao receber cStat 656, pausa global por 1h).
 * - Escalonamento das unidades na janela de 3h (evita picos).
 */
class GuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function unidade(): Unidade
    {
        $tenant = Tenant::factory()->create();

        return Unidade::factory()->create(['tenant_id' => $tenant->id]);
    }

    public function test_656_abre_circuit_breaker_global_por_tempo_configurado(): void
    {
        $unidade = $this->unidade();
        $guard = app(SefazGuard::class);

        $this->assertFalse($guard->isOpen());

        $guard->tripCircuitBreaker($unidade);

        $this->assertTrue($guard->isOpen());

        // Após o TTL (1h), fecha novamente.
        $this->travel(61)->minutes();

        $this->assertFalse($guard->isOpen());
    }

    public function test_circuit_breaker_e_global_nao_por_unidade(): void
    {
        $guard = app(SefazGuard::class);
        $guard->tripCircuitBreaker($this->unidade());

        // Outra unidade/tenant enxerga o breaker aberto (é global).
        $this->assertTrue($guard->isOpen());
    }

    public function test_reset_fecha_o_circuit_breaker(): void
    {
        $guard = app(SefazGuard::class);
        $guard->tripCircuitBreaker($this->unidade());

        $guard->reset();

        $this->assertFalse($guard->isOpen());
    }

    public function test_rate_limiter_permite_ate_o_teto_e_rejeita_acima(): void
    {
        config(['nfegov.sefaz.rate_per_minute' => 5]);

        $guard = app(SefazGuard::class);

        // 5 permitidas.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($guard->permitirChamada(), "Chamada {$i} deveria ser permitida.");
        }

        // 6ª rejeitada.
        $this->assertFalse($guard->permitirChamada());
    }

    public function test_before_call_lanca_quando_circuit_breaker_aberto(): void
    {
        $unidade = $this->unidade();
        $guard = app(SefazGuard::class);
        $guard->tripCircuitBreaker($unidade);

        $this->expectException(\RuntimeException::class);

        $guard->beforeCall($unidade);
    }

    public function test_unidades_sao_escalonadas_na_janela(): void
    {
        $guard = app(SefazGuard::class);

        // 10 unidades → 10 atrasos (em segundos) distintos e crescentes,
        // todos dentro da janela de 180 minutos.
        $slots = [];
        for ($i = 0; $i < 10; $i++) {
            $slots[] = $guard->slotPara($this->unidade());
        }

        $this->assertCount(10, $slots);
        $this->assertSame($slots, array_values(array_unique($slots)), 'Os slots devem ser distintos.');

        $janelaSegundos = 180 * 60;
        foreach ($slots as $s) {
            $this->assertGreaterThanOrEqual(0, $s);
            $this->assertLessThanOrEqual($janelaSegundos, $s);
        }
    }
}
<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantContextMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);

        Route::middleware(['web', 'auth:sanctum', 'tenant'])->prefix('api/_t')->group(function () {
            Route::get('/ctx', fn () => response()->json(['tenant' => TenantContext::id()]));
        });
    }

    public function test_usuario_sem_vinculo_recebe_403(): void
    {
        $u = User::factory()->create(['tenant_id' => null]);
        $u->assignRole('operador');

        $this->actingAs($u)->getJson('/api/_t/ctx')->assertForbidden();
    }

    public function test_usuario_com_vinculo_usa_o_primeiro(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->assignRole('operador');

        $this->actingAs($u)->getJson('/api/_t/ctx')->assertOk()->assertJsonPath('tenant', $a->id);
    }

    public function test_super_admin_sem_preferencia_ve_todas_contexto_nulo(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');
        $super->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($super)->getJson('/api/_t/ctx')->assertOk()->assertJsonPath('tenant', null);
    }

    public function test_sessao_define_o_tenant_ativo(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->tenants()->attach($b->id);
        $u->assignRole('operador');

        $this->actingAs($u)
            ->withSession(['tenant_id' => $b->id])
            ->getJson('/api/_t/ctx')
            ->assertOk()
            ->assertJsonPath('tenant', $b->id);
    }
}
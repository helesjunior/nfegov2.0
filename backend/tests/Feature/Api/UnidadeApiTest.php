<?php

namespace Tests\Feature\Api;

use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API REST de Unidades gestoras e execuções de sincronização.
 */
class UnidadeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuario(Tenant $tenant, string $papel): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        // Papéis administrativos exigem MFA confirmado (middleware `mfa`).
        if (in_array($papel, ['super-admin', 'admin-tenant'], true)) {
            $u->forceFill([
                'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
                'two_factor_confirmed_at' => now(),
                'mfa_recent_at' => now(),
            ])->save();
        }

        return $u;
    }

    public function test_lista_unidades_do_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        Unidade::factory()->count(2)->create(['tenant_id' => $a->id]);
        Unidade::factory()->create(['tenant_id' => $b->id]);

        $resp = $this->actingAs($this->usuario($a, 'admin-tenant'))->getJson('/api/unidades');

        $resp->assertOk();
        $this->assertSame(2, $resp->json('meta.total'));
    }

    public function test_admin_cria_unidade(): void
    {
        $tenant = Tenant::factory()->create();

        $resp = $this->actingAs($this->usuario($tenant, 'admin-tenant'))
            ->postJson('/api/unidades', [
                'nome' => 'Unidade Nova',
                'cnpj' => '11222333000181',
                'uf' => 'SP',
                'tp_amb' => 2,
            ]);

        $resp->assertCreated();
        $this->assertDatabaseHas('unidades', [
            'tenant_id' => $tenant->id,
            'cnpj' => '11222333000181',
        ]);
    }

    public function test_usuario_sem_permissao_nao_cria_unidade(): void
    {
        $tenant = Tenant::factory()->create();

        $resp = $this->actingAs($this->usuario($tenant, 'consulta'))
            ->postJson('/api/unidades', [
                'nome' => 'Unidade X',
                'cnpj' => '99888777000166',
                'uf' => 'SP',
            ]);

        $resp->assertForbidden();
    }

    public function test_nao_permite_cnpj_duplicado_no_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        Unidade::factory()->create(['tenant_id' => $tenant->id, 'cnpj' => '11222333000181']);

        $resp = $this->actingAs($this->usuario($tenant, 'admin-tenant'))
            ->postJson('/api/unidades', [
                'nome' => 'Duplicada',
                'cnpj' => '11222333000181',
                'uf' => 'SP',
            ]);

        $resp->assertStatus(422);
    }

    public function test_autorizacao_usa_vinculo_e_nao_a_coluna_legada(): void
    {
        // O usuário TEM vínculo (N:N) com o tenant A, mas a coluna legada
        // `users.tenant_id` aponta para o tenant B. A autorização deve seguir o
        // VÍNCULO: editar a unidade de A é permitido, e a de B é negada — mesmo
        // que a coluna legada diga o contrário.
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = Unidade::factory()->create(['tenant_id' => $a->id]);
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);

        $u = User::factory()->create(['tenant_id' => $b->id]);
        $u->tenants()->attach($a->id);
        $u->assignRole('admin-tenant');
        $u->forceFill([
            'two_factor_secret' => app(\App\Services\TwoFactorService::class)->gerarSegredo(),
            'two_factor_confirmed_at' => now(),
            'mfa_recent_at' => now(),
        ])->save();

        // Mesmo com `tenant_id` legado = B, o vínculo com A autoriza a unidade A.
        $this->actingAs($u)->putJson("/api/unidades/{$ua->id}", [
            'nome' => 'Editada por vínculo',
            'cnpj' => $ua->cnpj,
            'uf' => $ua->uf,
        ])->assertOk();

        // E o `tenant_id` legado = B NÃO autoriza a unidade B (só o vínculo conta).
        $this->actingAs($u)->putJson("/api/unidades/{$ub->id}", [
            'nome' => 'Tentativa indevida',
            'cnpj' => $ub->cnpj,
            'uf' => $ub->uf,
        ])->assertNotFound();
    }

    public function test_lista_sync_runs_do_tenant(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $ua = Unidade::factory()->create(['tenant_id' => $a->id]);
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);

        \App\Models\SyncRun::factory()->count(2)->create(['tenant_id' => $a->id, 'unidade_id' => $ua->id]);
        \App\Models\SyncRun::factory()->create(['tenant_id' => $b->id, 'unidade_id' => $ub->id]);

        $resp = $this->actingAs($this->usuario($a, 'operador'))->getJson('/api/sync-runs');

        $resp->assertOk();
        $this->assertSame(2, $resp->json('meta.total'));
    }
}
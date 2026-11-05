<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TenantUserMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracao_copia_tenant_id_legado_para_pivo(): void
    {
        // Exercita de verdade o backfill da migração: recria a tabela do zero
        // (como no primeiro `up()`), com um usuário que só tinha a coluna legada
        // preenchida, e confere que o vínculo (N:N) foi criado pela migração.
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);

        // Remove o estado criado pelo `comTenant`/migração anterior para que o
        // único vínculo possível venha do backfill que vamos reexecutar.
        DB::table('tenant_user')->where('user_id', $u->id)->delete();
        Schema::drop('tenant_user');

        // Reexecuta a migração: cria a tabela e copia users.tenant_id → pivô.
        $migracao = require database_path('migrations/2026_11_04_130000_create_tenant_user_table.php');
        $migracao->up();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
        ]);
    }

    public function test_helper_comtenan_cria_vinculo(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create();

        $this->assertDatabaseHas('tenant_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
        ]);
        $this->assertTrue($u->tenants()->whereKey($tenant->id)->exists());
        $this->assertSame($tenant->id, (int) $u->tenant_id);
    }

    public function test_relacao_tem_e_pertence(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $a->id]);
        $u->tenants()->attach([$a->id, $b->id]);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $u->tenants()->pluck('tenants.id')->all());
        $this->assertTrue($a->users()->whereKey($u->id)->exists());
    }

    public function test_vinculo_e_unico_por_par(): void
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);
        $u->tenants()->attach($tenant->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $u->tenants()->attach($tenant->id);
    }
}
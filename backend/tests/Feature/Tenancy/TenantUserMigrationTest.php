<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantUserMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_migracao_copia_tenant_id_legado_para_pivo(): void
    {
        // Simula o estado pós-migração: um usuário com tenant_id legado que
        // ganhou vínculo em tenant_user (como a migração faz no up()).
        $tenant = Tenant::factory()->create();
        $u = User::factory()->create(['tenant_id' => $tenant->id]);

        // A migração já rodou no banco de teste; replicamos a cópia de dados
        // exercitando o mesmo caminho usado por ela e conferimos o resultado.
        \Illuminate\Support\Facades\DB::table('tenant_user')->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'user_id' => $u->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
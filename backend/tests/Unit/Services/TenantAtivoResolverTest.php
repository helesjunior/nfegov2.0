<?php

namespace Tests\Unit\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantAtivoResolver;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantAtivoResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function resolver(): TenantAtivoResolver
    {
        return app(TenantAtivoResolver::class);
    }

    public function test_super_admin_sem_preferencia_resolve_todas(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertNull($this->resolver()->paraUsuario($super, null));
        $this->assertTrue($this->resolver()->podeTodas($super));
    }

    public function test_super_admin_com_preferencia_ativa_resolve_ela(): void
    {
        $t = Tenant::factory()->create(['ativo' => true]);
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertSame($t->id, $this->resolver()->paraUsuario($super, $t->id));
    }

    public function test_super_admin_com_preferencia_inexistente_cai_para_todas(): void
    {
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertNull($this->resolver()->paraUsuario($super, 99999));
    }

    public function test_super_admin_com_preferencia_inativa_cai_para_todas(): void
    {
        $t = Tenant::factory()->create(['ativo' => false]);
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertNull($this->resolver()->paraUsuario($super, $t->id));
    }

    public function test_usuario_com_vinculo_usa_primeiro_quando_sem_preferencia(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->tenants()->attach($b->id);

        $this->assertSame(min($a->id, $b->id), $this->resolver()->paraUsuario($u, null));
    }

    public function test_usuario_respeita_preferencia_se_tiver_vinculo_ativo(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $b = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();
        $u->tenants()->attach($b->id);

        $this->assertSame($b->id, $this->resolver()->paraUsuario($u, $b->id));
    }

    public function test_usuario_com_preferencia_sem_vinculo_cai_para_o_primeiro(): void
    {
        $a = Tenant::factory()->create(['ativo' => true]);
        $outro = Tenant::factory()->create(['ativo' => true]);
        $u = User::factory()->comTenant($a)->create();

        $this->assertSame($a->id, $this->resolver()->paraUsuario($u, $outro->id));
    }

    public function test_usuario_sem_vinculo_ativo_e_sinalizado(): void
    {
        $t = Tenant::factory()->create(['ativo' => false]);
        $u = User::factory()->comTenant($t)->create();

        $this->assertTrue($this->resolver()->usuarioSemVinculo($u));
    }

    public function test_disponiveis_para_super_admin_e_todas_as_ativas(): void
    {
        Tenant::factory()->create(['ativo' => true]);
        Tenant::factory()->create(['ativo' => false]);
        $super = User::factory()->create(['tenant_id' => null]);
        $super->assignRole('super-admin');

        $this->assertSame(1, $this->resolver()->disponiveis($super)->count());
    }
}

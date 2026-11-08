<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    public function test_papeis_esperados_existem(): void
    {
        foreach (['super-admin', 'admin-tenant', 'operador', 'consulta'] as $papel) {
            $this->assertTrue(
                \Spatie\Permission\Models\Role::where('name', $papel)->exists(),
                "Papel {$papel} não encontrado."
            );
        }
    }

    public function test_operador_nao_pode_gerenciar_certificado(): void
    {
        $u = User::factory()->create();
        $u->assignRole('operador');

        $this->assertFalse($u->can('certificado.gerenciar'));
    }

    public function test_admin_tenant_pode_gerenciar_certificado(): void
    {
        $u = User::factory()->create();
        $u->assignRole('admin-tenant');

        $this->assertTrue($u->can('certificado.gerenciar'));
    }

    public function test_consulta_opera_as_notas_sem_manifestar_nem_gerir(): void
    {
        $u = User::factory()->create();
        $u->assignRole('consulta');

        $this->assertTrue($u->can('nfe.view'));
        $this->assertTrue($u->can('nfe.export'));
        $this->assertTrue($u->can('sync.executar'));
        $this->assertFalse($u->can('nfe.manifestar'));
        $this->assertFalse($u->can('unidade.view'));
        $this->assertFalse($u->can('unidade.gerenciar'));
        $this->assertFalse($u->can('certificado.renovar'));
        $this->assertFalse($u->can('usuario.gerenciar'));
    }

    public function test_operador_ve_unidades_e_so_renova_certificado(): void
    {
        $u = User::factory()->create();
        $u->assignRole('operador');

        $this->assertTrue($u->can('unidade.view'));
        $this->assertTrue($u->can('certificado.renovar'));
        $this->assertTrue($u->can('nfe.manifestar'));
        $this->assertFalse($u->can('unidade.gerenciar'));
        $this->assertFalse($u->can('usuario.gerenciar'));
    }

    public function test_super_admin_tem_todas_as_permissoes(): void
    {
        $u = User::factory()->create();
        $u->assignRole('super-admin');

        $this->assertTrue($u->can('certificado.gerenciar'));
        $this->assertTrue($u->can('usuario.gerenciar'));
        $this->assertTrue($u->can('auditoria.view'));
    }
}
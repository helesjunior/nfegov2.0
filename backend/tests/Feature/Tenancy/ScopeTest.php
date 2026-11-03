<?php

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Modelo de apoio usado apenas para exercitar o trait BelongsToTenant
 * contra a tabela real de usuários (já existente no schema).
 */
class DummyTenantModel extends Model
{
    use BelongsToTenant;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = true;
}

class ScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cria um tenant na tabela raiz, respeitando a constraint de foreign key.
     */
    private function criarTenant(int $id, string $nome = 'Órgão'): void
    {
        DB::table('tenants')->insert([
            'id' => $id,
            'nome' => $nome,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_registros_ficam_isolados_por_tenant(): void
    {
        $this->criarTenant(1, 'Orgao A');
        $this->criarTenant(2, 'Orgao B');

        TenantContext::runAs(1, fn () => DummyTenantModel::create([
            'name' => 'A', 'email' => 'a@exemplo.test', 'password' => 'x',
        ]));
        TenantContext::runAs(2, fn () => DummyTenantModel::create([
            'name' => 'B', 'email' => 'b@exemplo.test', 'password' => 'x',
        ]));

        $vistos1 = TenantContext::runAs(1, fn () => DummyTenantModel::pluck('name')->all());
        $vistos2 = TenantContext::runAs(2, fn () => DummyTenantModel::pluck('name')->all());

        $this->assertSame(['A'], $vistos1);
        $this->assertSame(['B'], $vistos2);
    }

    public function test_tenant_id_e_preenchido_automaticamente(): void
    {
        $this->criarTenant(7, 'Orgao C');

        $model = TenantContext::runAs(7, fn () => DummyTenantModel::create([
            'name' => 'C', 'email' => 'c@exemplo.test', 'password' => 'x',
        ]));

        $this->assertSame(7, (int) $model->tenant_id);
    }

    public function test_run_as_restaura_o_contexto_anterior(): void
    {
        TenantContext::set(5);
        TenantContext::runAs(9, fn () => null);

        $this->assertSame(5, TenantContext::id());
    }

    public function test_sem_contexto_nao_aplica_filtro_de_tenant(): void
    {
        $this->criarTenant(1, 'Orgao A');

        TenantContext::runAs(1, fn () => DummyTenantModel::create([
            'name' => 'A', 'email' => 'a@exemplo.test', 'password' => 'x',
        ]));

        // Sem tenant definido, nenhuma linha é filtrada (uso administrativo/global).
        TenantContext::clear();
        $this->assertSame(1, DummyTenantModel::count());
    }
}
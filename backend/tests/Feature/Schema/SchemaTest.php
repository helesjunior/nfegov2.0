<?php

namespace Tests\Feature\Schema;

use App\Models\Nfe;
use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_unidade_exige_cnpj(): void
    {
        $this->expectException(QueryException::class);

        Unidade::factory()->create(['cnpj' => null]);
    }

    public function test_nfe_chave_e_unica_por_tenant_mas_repetivel_entre_tenants(): void
    {
        $chave = str_repeat('1', 44);

        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $ua = Unidade::factory()->create(['tenant_id' => $a->id]);
        $ub = Unidade::factory()->create(['tenant_id' => $b->id]);

        $n1 = Nfe::factory()->create(['tenant_id' => $a->id, 'unidade_id' => $ua->id, 'chave' => $chave]);

        // Mesmo tenant + mesma chave -> deve violar a unicidade.
        // Usa savepoint para não abortar a transação externa do PostgreSQL.
        $violou = false;
        try {
            DB::transaction(fn () => Nfe::factory()->create([
                'tenant_id' => $a->id, 'unidade_id' => $ua->id, 'chave' => $chave,
            ]));
        } catch (QueryException) {
            $violou = true;
        }
        $this->assertTrue($violou, 'Esperava violação de unicidade para chave repetida no mesmo tenant.');

        // Outro tenant pode ter a mesma chave.
        $n2 = Nfe::factory()->create(['tenant_id' => $b->id, 'unidade_id' => $ub->id, 'chave' => $chave]);

        $this->assertNotSame($n1->id, $n2->id);
        $this->assertSame($chave, $n2->chave);
    }

    public function test_relacoes_basicas_do_dominio(): void
    {
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $nfe = Nfe::factory()->create(['tenant_id' => $tenant->id, 'unidade_id' => $unidade->id]);

        $this->assertTrue($unidade->tenant->is($tenant));
        $this->assertTrue($nfe->unidade->is($unidade));
        $this->assertCount(1, $tenant->unidades);
    }
}
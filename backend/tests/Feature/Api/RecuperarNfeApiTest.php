<?php

namespace Tests\Feature\Api;

use App\Jobs\RecuperarNfeJob;
use App\Models\Tenant;
use App\Models\Unidade;
use App\Models\User;
use Database\Seeders\RolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * API da recuperação manual de NF-e (`POST /api/nfes/recuperar`).
 *
 * Regras centrais:
 * - Disparar a busca exige `sync.executar` — o papel `consulta` (somente
 *   leitura) recebe 403, pois a busca pode enviar Ciência da Operação.
 * - A resposta é imediata (202): o trabalho roda em job.
 * - Um pedido idêntico enquanto o job anterior está na fila recebe 409, sem
 *   enfileirar um segundo job.
 * - Precisa informar OU a chave (44 dígitos) OU o NSU (>= 1).
 */
class RecuperarNfeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesSeeder::class);
    }

    private function usuarioDoTenant(Tenant $tenant, string $papel): User
    {
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papel);

        return $u;
    }

    public function test_operador_enfileira_e_responde_202(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $resp = $this->actingAs($this->usuarioDoTenant($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'nsu' => 150]);

        $resp->assertStatus(202);
        Queue::assertPushed(RecuperarNfeJob::class);
    }

    public function test_consulta_recebe_403(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuarioDoTenant($tenant, 'consulta'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'nsu' => 150])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_validacao_exige_um_dos_dois(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuarioDoTenant($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id])
            ->assertStatus(422);
    }

    public function test_nsu_zero_invalido(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuarioDoTenant($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'nsu' => 0])
            ->assertStatus(422);
    }

    public function test_chave_invalida_recebe_422(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);

        $this->actingAs($this->usuarioDoTenant($tenant, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidade->id, 'chave' => '123'])
            ->assertStatus(422);
    }

    public function test_unidade_de_outro_tenant_recebe_404(): void
    {
        Queue::fake();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $unidadeB = Unidade::factory()->create(['tenant_id' => $b->id]);

        // Unidade de outro tenant: 404 (nunca revelamos a existência).
        $this->actingAs($this->usuarioDoTenant($a, 'operador'))
            ->postJson('/api/nfes/recuperar', ['unidade_id' => $unidadeB->id, 'nsu' => 150])
            ->assertStatus(404);

        Queue::assertNothingPushed();
    }

    public function test_pedido_duplicado_recebe_409(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $unidade = Unidade::factory()->create(['tenant_id' => $tenant->id]);
        $user = $this->usuarioDoTenant($tenant, 'operador');

        $payload = ['unidade_id' => $unidade->id, 'nsu' => 150];

        // 1º pedido: aceito.
        $this->actingAs($user)->postJson('/api/nfes/recuperar', $payload)->assertStatus(202);

        // 2º pedido idêntico (job ainda na fila): 409, sem segundo job.
        $this->actingAs($user)->postJson('/api/nfes/recuperar', $payload)->assertStatus(409);

        Queue::assertPushed(RecuperarNfeJob::class, 1);
    }
}

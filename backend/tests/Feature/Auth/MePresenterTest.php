<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MePresenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesSeeder::class);
    }

    private function usuario(array $papeis): User
    {
        $tenant = Tenant::factory()->create();
        $u = User::factory()->comTenant($tenant)->create();
        $u->assignRole($papeis);

        return $u;
    }

    public function test_me_expoe_mfa_obrigatorio_para_papel_administrativo(): void
    {
        $u = $this->usuario(['admin-tenant']);

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.mfa_obrigatorio', true);
    }

    public function test_me_nao_obriga_mfa_para_operador(): void
    {
        $u = $this->usuario(['operador']);

        $this->actingAs($u)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.mfa_obrigatorio', false)
            ->assertJsonPath('user.mfa_pendente', false);
    }
}
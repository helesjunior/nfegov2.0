<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unidade>
 */
class UnidadeFactory extends Factory
{
    protected $model = Unidade::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'codigo_unidade' => (string) fake()->unique()->numberBetween(1, 9999),
            'cnpj' => fake()->unique()->numerify('##############'),
            'ie' => fake()->numerify('#########'),
            'nome' => fake()->company(),
            'nome_resumido' => fake()->word(),
            'ativo' => true,
        ];
    }
}
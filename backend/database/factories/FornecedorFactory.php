<?php

namespace Database\Factories;

use App\Models\Fornecedor;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fornecedor>
 */
class FornecedorFactory extends Factory
{
    protected $model = Fornecedor::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'cnpj' => fake()->unique()->numerify('##############'),
            'ie' => fake()->numerify('#########'),
            'nome' => fake()->company(),
            'endereco' => fake()->streetName(),
            'endereco_numero' => fake()->buildingNumber(),
            'bairro' => fake()->word(),
            'cep' => fake()->numerify('########'),
            'telefone' => fake()->numerify('###########'),
        ];
    }
}
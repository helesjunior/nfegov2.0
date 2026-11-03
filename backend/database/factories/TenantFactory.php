<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'nome' => 'Órgão '.fake()->unique()->numberBetween(1, 9999),
            'cnpj' => fake()->unique()->numerify('##############'),
            'ativo' => true,
        ];
    }
}
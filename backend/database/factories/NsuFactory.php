<?php

namespace Database\Factories;

use App\Models\Nsu;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nsu>
 */
class NsuFactory extends Factory
{
    protected $model = Nsu::class;

    public function definition(): array
    {
        return [
            'unidade_id' => Unidade::factory(),
            'ultimo_nsu' => 0,
            'max_nsu' => 0,
            'dh_resp' => now(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Nsu $nsu) {
            if ($nsu->unidade_id && ! $nsu->tenant_id) {
                $nsu->tenant_id = Unidade::withoutGlobalScopes()->find($nsu->unidade_id)?->tenant_id;
            }
        });
    }
}
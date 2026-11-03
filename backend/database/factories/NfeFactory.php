<?php

namespace Database\Factories;

use App\Models\Nfe;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nfe>
 */
class NfeFactory extends Factory
{
    protected $model = Nfe::class;

    public function definition(): array
    {
        return [
            'unidade_id' => Unidade::factory(),
            'chave' => fake()->unique()->numerify(str_repeat('#', 44)),
            'numero' => (string) fake()->numberBetween(1, 999999),
            'serie' => '1',
            'modelo' => '55',
            'emitente_cnpj' => fake()->numerify('##############'),
            'emitente_nome' => fake()->company(),
            'dest_cnpj' => fake()->numerify('##############'),
            'valor_total' => fake()->randomFloat(2, 10, 100000),
            'natureza_operacao' => 'COMPRA',
            'data_emissao' => now()->subDays(fake()->numberBetween(0, 30)),
            'cstat' => '100',
            'estado' => Nfe::ESTADO_COMPLETO,
            'emitente_proprio' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Nfe $nfe) {
            if ($nfe->unidade_id && ! $nfe->tenant_id) {
                $nfe->tenant_id = Unidade::withoutGlobalScopes()->find($nfe->unidade_id)?->tenant_id;
            }
        });
    }

    public function completo(): static
    {
        return $this->state(fn () => ['estado' => Nfe::ESTADO_COMPLETO]);
    }

    public function resumo(): static
    {
        return $this->state(fn () => ['estado' => Nfe::ESTADO_RESUMO]);
    }
}
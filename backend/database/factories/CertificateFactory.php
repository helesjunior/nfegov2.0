<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    protected $model = Certificate::class;

    public function definition(): array
    {
        return [
            'arquivo_path' => 'certificados/'.fake()->uuid().'.pfx',
            'senha_encrypted' => fake()->password(),
            'cnpj' => fake()->numerify('##############'),
            'titular' => fake()->company(),
            'valido_de' => now()->subYear(),
            'valido_ate' => now()->addYear(),
            'fingerprint' => fake()->sha256(),
            'emissor' => 'AC Teste',
            'ativo' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Certificate $c) {
            if (! $c->unidade_id) {
                $unidade = Unidade::factory()->create();
                $c->unidade_id = $unidade->id;
                $c->tenant_id = $c->tenant_id ?: $unidade->tenant_id;
                $c->cnpj = $c->cnpj ?: $unidade->cnpj;
            } elseif (! $c->tenant_id) {
                $c->tenant_id = Unidade::withoutGlobalScopes()->find($c->unidade_id)?->tenant_id;
            }
        });
    }

    public function vencendoEm(int $dias): static
    {
        return $this->state(fn () => ['valido_ate' => now()->addDays($dias)]);
    }

    public function vencido(): static
    {
        return $this->state(fn () => ['valido_ate' => now()->subDay()]);
    }
}
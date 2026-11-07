<?php

namespace Database\Factories;

use App\Models\Nfse;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Nfse>
 */
class NfseFactory extends Factory
{
    protected $model = Nfse::class;

    public function definition(): array
    {
        $emissao = now()->subDays(fake()->numberBetween(0, 30));

        return [
            'unidade_id' => Unidade::factory(),
            // município (7) + ambGer/tpInsc (2) + CNPJ (14) + número (13) + AAMM (4) + código (9) + DV (1)
            'chave' => '5300108'.'22'.fake()->numerify(str_repeat('#', 14)).fake()->numerify(str_repeat('#', 13))
                .$emissao->format('ym').fake()->numerify(str_repeat('#', 10)),
            'nsu' => fake()->unique()->numberBetween(1, 999999),
            'papel' => Nfse::PAPEL_TOMADOR,
            'numero' => (string) fake()->numberBetween(1, 9999),
            'municipio_emissor_codigo' => '5300108',
            'municipio_emissor_nome' => 'Brasília',
            'prestador_documento' => fake()->numerify('##############'),
            'prestador_nome' => fake()->company(),
            'tomador_documento' => fake()->numerify('##############'),
            'tomador_nome' => fake()->company(),
            'data_emissao' => $emissao,
            'competencia' => $emissao->toDateString(),
            'codigo_servico' => '141302',
            'servico_nome' => 'Serralheria.',
            'descricao_servico' => 'Serviço de teste',
            'valor_servico' => fake()->randomFloat(2, 10, 10000),
            'valor_liquido' => fake()->randomFloat(2, 10, 10000),
            'iss_retido' => false,
            'situacao' => Nfse::SITUACAO_NORMAL,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Nfse $nfse) {
            if ($nfse->unidade_id && ! $nfse->tenant_id) {
                $nfse->tenant_id = Unidade::withoutGlobalScopes()->find($nfse->unidade_id)?->tenant_id;
            }
        });
    }
}

<?php

namespace Database\Factories;

use App\Models\SyncRun;
use App\Models\Unidade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    protected $model = SyncRun::class;

    public function definition(): array
    {
        return [
            'unidade_id' => Unidade::factory(),
            'iniciado_em' => now(),
            'nsu_inicial' => 0,
            'nsu_final' => 0,
            'docs_count' => 0,
            'status' => SyncRun::STATUS_EM_ANDAMENTO,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (SyncRun $run) {
            if ($run->unidade_id && ! $run->tenant_id) {
                $run->tenant_id = Unidade::withoutGlobalScopes()->find($run->unidade_id)?->tenant_id;
            }
        });
    }
}
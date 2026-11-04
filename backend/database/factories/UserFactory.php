<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Cria o usuário já vinculado a uma entidade (pivô tenant_user + coluna legada).
     */
    public function comTenant(\App\Models\Tenant|int $tenant): static
    {
        $tenantId = $tenant instanceof \App\Models\Tenant ? $tenant->id : $tenant;

        return $this->state(fn () => ['tenant_id' => $tenantId])
            ->afterCreating(fn (User $user) => $user->tenants()->syncWithoutDetaching([$tenantId]));
    }
}

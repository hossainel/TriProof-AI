<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => Hash::make('password'),
            'role' => UserRole::GlobalBuyer,
            'language_pref' => 'en',
            'factory_id' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin, 'factory_id' => null]);
    }

    public function buyer(): static
    {
        return $this->state(fn () => ['role' => UserRole::GlobalBuyer, 'factory_id' => null]);
    }

    public function factory(string $factoryId = 'DHK04'): static
    {
        return $this->state(fn () => [
            'role' => UserRole::FactoryQa,
            'factory_id' => $factoryId,
            'language_pref' => 'bn',
        ]);
    }
}

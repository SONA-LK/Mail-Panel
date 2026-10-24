<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'google_id' => null,
            'role' => User::ROLE_READONLY,
            'active' => true,
        ];
    }

    public function superAdmin(): static
    {
        return $this->state(['role' => User::ROLE_SUPER]);
    }

    public function manager(): static
    {
        return $this->state(['role' => User::ROLE_MANAGER]);
    }
}

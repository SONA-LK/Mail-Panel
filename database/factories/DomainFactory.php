<?php

namespace Database\Factories;

use App\Models\Domain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Domain>
 */
class DomainFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->domainName(),
            'default_quota_mb' => 1024,
            'naming_pattern' => 'free',
            'catchall_policy' => 'discard',
            'forwarding_policy' => 'any',
            'suspend_days' => 30,
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Plan>
 */
class PlanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement(['sandbox', 'basic', 'pro']),
            'monthly_request_limit' => $this->faker->numberBetween(100, 10000),
            'rate_limit_per_minute' => $this->faker->numberBetween(10, 120),
            'is_active' => $this->faker->boolean(90), // 90% chance of being active
        ];
    }
}

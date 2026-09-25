<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
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
            'code' => Str::upper(fake()->unique()->lexify('PLAN-????')), 'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(), 'price_minor' => fake()->numberBetween(100_000, 5_000_000),
            'currency' => 'PKR', 'billing_interval' => 'monthly', 'usage_limits' => ['users' => 10], 'features' => [], 'is_active' => true,
        ];
    }
}

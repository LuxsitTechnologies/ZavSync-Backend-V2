<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\IntelligenceScenario;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntelligenceScenario>
 */
class IntelligenceScenarioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'created_by' => User::factory(), 'name' => 'Revenue downside', 'scenario_type' => 'REVENUE_CHANGE', 'status' => 'COMPLETED', 'assumptions' => ['change_bps' => -1000], 'baseline' => ['revenue_minor' => 1000000], 'scenario' => ['revenue_minor' => 900000], 'delta' => ['revenue_minor' => -100000], 'idempotency_key' => fake()->unique()->uuid(), 'idempotency_hash' => fake()->sha256(), 'calculated_at' => now()];
    }
}

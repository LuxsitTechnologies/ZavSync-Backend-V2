<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\ScheduledIntelligenceRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduledIntelligenceRun>
 */
class ScheduledIntelligenceRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'run_type' => 'REFRESH', 'status' => 'COMPLETED', 'idempotency_key' => fake()->unique()->uuid(), 'metrics' => ['signals' => 1], 'started_at' => now(), 'completed_at' => now()];
    }
}

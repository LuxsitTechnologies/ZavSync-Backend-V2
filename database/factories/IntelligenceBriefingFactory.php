<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\IntelligenceBriefing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntelligenceBriefing>
 */
class IntelligenceBriefingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'period' => 'TODAY', 'period_start' => today(), 'period_end' => today(), 'status' => 'DETERMINISTIC', 'structured_data' => ['sections' => [], 'top_priorities' => []], 'narrative' => null, 'fingerprint' => fake()->unique()->sha256(), 'generated_at' => now()];
    }
}

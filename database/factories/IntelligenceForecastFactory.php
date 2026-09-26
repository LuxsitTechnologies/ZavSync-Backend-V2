<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\IntelligenceForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntelligenceForecast>
 */
class IntelligenceForecastFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'metric' => 'cash_position', 'source_module' => 'banking', 'method' => 'AUTHORITATIVE_CASH_FORECAST', 'horizon_days' => 30, 'status' => 'READY', 'source_data' => ['current_cash_minor' => 500000], 'assumptions' => ['scheduled_receivables' => true], 'projection_points' => [['day' => 30, 'value_minor' => 450000]], 'confidence_bps' => 7000, 'limitations' => 'Based on recorded due dates.', 'generated_at' => now(), 'fingerprint' => fake()->unique()->sha256()];
    }
}

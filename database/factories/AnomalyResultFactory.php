<?php

namespace Database\Factories;

use App\Models\AnomalyResult;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnomalyResult>
 */
class AnomalyResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'category' => 'ACCOUNTING', 'source_module' => 'accounting', 'metric' => 'monthly_expense', 'method' => 'PERIOD_OVER_PERIOD', 'observed_value' => 125000, 'expected_value' => 100000, 'deviation_value' => 25000, 'deviation_bps' => 2500, 'threshold_bps' => 2000, 'sample_size' => 4, 'window_start' => now()->subMonths(4)->startOfMonth(), 'window_end' => now()->endOfMonth(), 'evaluated_at' => now(), 'status' => 'ACTIVE', 'fingerprint' => fake()->unique()->sha256(), 'source_metrics' => ['history' => [90000, 100000, 110000, 125000]], 'explanation' => 'Observed value is 25.00% above the comparison baseline.'];
    }
}

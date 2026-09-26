<?php

namespace Database\Factories;

use App\Models\AiProviderReconciliation;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiProviderReconciliation>
 */
class AiProviderReconciliationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'provider' => 'openai', 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'internal_cost_minor' => 1000, 'provider_cost_minor' => null, 'difference_minor' => null, 'status' => 'NOT_AVAILABLE'];
    }
}

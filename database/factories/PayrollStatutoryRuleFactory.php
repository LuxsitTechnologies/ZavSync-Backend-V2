<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollComponent;
use App\Models\PayrollStatutoryRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollStatutoryRule>
 */
class PayrollStatutoryRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_component_id' => PayrollComponent::factory(), 'jurisdiction' => 'PK', 'rule_type' => 'INCOME_TAX', 'version' => fake()->unique()->numerify('v#'), 'effective_from' => '2026-01-01', 'effective_to' => null, 'threshold_from' => 0, 'threshold_to' => null, 'rate_bps' => 500, 'fixed_amount' => 0, 'minimum_amount' => null, 'maximum_amount' => null, 'metadata' => null, 'is_active' => true, 'created_by' => User::factory()];
    }
}

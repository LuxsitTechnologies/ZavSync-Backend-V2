<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollComponent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollComponent>
 */
class PayrollComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'code' => fake()->unique()->bothify('PAY-###'), 'name' => fake()->words(2, true), 'type' => 'EARNINGS', 'calculation_method' => 'fixed', 'fixed_amount' => 10_000, 'rate_bps' => null, 'calculation_base' => 'basic', 'is_taxable' => true, 'is_active' => true, 'effective_from' => '2026-01-01', 'effective_to' => null, 'gl_account_id' => null, 'liability_account_id' => null, 'description' => null, 'created_by' => User::factory(), 'updated_by' => null];
    }

    public function deduction(): static
    {
        return $this->state(fn (): array => ['type' => 'DEDUCTIONS', 'is_taxable' => false]);
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollAdjustment;
use App\Models\PayrollComponent;
use App\Models\PayrollEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollAdjustment>
 */
class PayrollAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_entry_id' => PayrollEntry::factory(), 'payroll_component_id' => PayrollComponent::factory(), 'amount' => 10_000_00, 'reason' => 'Authorized payroll adjustment', 'created_by' => User::factory()];
    }
}

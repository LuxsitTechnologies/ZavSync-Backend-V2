<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PayrollEntry;
use App\Models\PayrollEntryLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollEntryLine>
 */
class PayrollEntryLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_entry_id' => PayrollEntry::factory(), 'payroll_component_id' => null, 'payroll_adjustment_id' => null, 'statutory_rule_id' => null, 'component_code' => 'BASIC', 'component_name' => 'Basic Salary', 'component_type' => 'EARNINGS', 'amount' => 100_000_00, 'is_taxable' => true, 'gl_account_id' => null, 'liability_account_id' => null, 'calculation_snapshot' => ['method' => 'profile_base_salary']];
    }
}

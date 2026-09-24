<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollBatch;
use App\Models\PayrollEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollEntry>
 */
class PayrollEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'payroll_batch_id' => PayrollBatch::factory(), 'employee_id' => Employee::factory(), 'employee_payroll_profile_id' => EmployeePayrollProfile::factory(), 'employee_code' => fake()->unique()->bothify('EMP-####'), 'employee_name' => fake()->name(), 'department' => 'Finance', 'designation' => 'Officer', 'base_salary' => 100_000_00, 'currency' => 'PKR', 'profile_snapshot' => ['components' => []], 'statutory_rule_snapshot' => [], 'gross_earnings' => 100_000_00, 'taxable_earnings' => 100_000_00, 'employee_deductions' => 0, 'employee_contributions' => 0, 'tax_amount' => 0, 'employer_contributions' => 0, 'reimbursements' => 0, 'net_pay' => 100_000_00, 'employer_total_cost' => 100_000_00];
    }
}

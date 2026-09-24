<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeePayrollProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeePayrollProfile>
 */
class EmployeePayrollProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'employee_id' => Employee::factory(), 'payroll_status' => 'active', 'pay_frequency' => 'monthly', 'base_salary' => 500_000_00, 'currency' => 'PKR', 'effective_from' => '2026-01-01', 'effective_to' => null, 'tax_identifier' => null, 'statutory_registration' => null, 'payment_financial_account_id' => null, 'employee_bank_reference' => null, 'created_by' => User::factory()];
    }
}

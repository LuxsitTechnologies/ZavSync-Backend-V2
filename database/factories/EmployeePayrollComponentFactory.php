<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmployeePayrollComponent;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollComponent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeePayrollComponent>
 */
class EmployeePayrollComponentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'employee_payroll_profile_id' => EmployeePayrollProfile::factory(), 'payroll_component_id' => PayrollComponent::factory(), 'fixed_amount' => null, 'rate_bps' => null, 'effective_from' => null, 'effective_to' => null, 'is_active' => true];
    }
}

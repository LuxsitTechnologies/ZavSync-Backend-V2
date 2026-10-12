<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeBeneficiaryAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeBeneficiaryAccount>
 */
class EmployeeBeneficiaryAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(),
            'employee_id' => fn (array $attributes): string => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'account_holder_name' => fake()->name(), 'account_number' => 'PK00-TEST-1234',
            'bank_name' => 'Example Bank', 'branch_name' => 'Main Branch', 'swift_code' => null,
            'created_by' => User::factory()];
    }
}

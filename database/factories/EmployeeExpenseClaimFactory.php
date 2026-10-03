<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeExpenseCategory;
use App\Models\EmployeeExpenseClaim;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeExpenseClaim>
 */
class EmployeeExpenseClaimFactory extends Factory
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
            'category_id' => fn (array $attributes): string => EmployeeExpenseCategory::factory()->create(['company_id' => $attributes['company_id']])->id,
            'title' => fake()->sentence(4), 'description' => fake()->sentence(), 'amount_minor' => 12500,
            'currency' => 'PKR', 'expense_date' => '2026-09-01', 'status' => 'DRAFT', 'version' => 1,
            'created_by' => User::factory()];
    }
}

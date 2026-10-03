<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmployeeExpenseCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeExpenseCategory>
 */
class EmployeeExpenseCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->word(),
            'is_active' => true, 'version' => 1, 'created_by' => User::factory()];
    }
}

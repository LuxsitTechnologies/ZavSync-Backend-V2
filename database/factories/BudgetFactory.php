<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'fiscal_year_id' => FiscalYear::factory(), 'name' => 'Operating Budget', 'version' => 1, 'status' => 'draft', 'currency' => 'PKR', 'description' => null, 'is_active' => false, 'created_by' => User::factory()];
    }
}

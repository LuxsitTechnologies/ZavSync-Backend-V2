<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'budget_id' => Budget::factory(), 'account_id' => Account::factory(), 'accounting_period_id' => AccountingPeriod::factory(), 'amount' => fake()->numberBetween(10000, 1000000)];
    }
}

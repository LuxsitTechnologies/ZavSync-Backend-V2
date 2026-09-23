<?php

namespace Database\Factories;

use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliation>
 */
class BankReconciliationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 100000);

        return ['company_id' => Company::factory(), 'sequence' => $sequence, 'number' => 'REC-2026-'.$sequence, 'financial_account_id' => FinancialAccount::factory(), 'bank_statement_import_id' => null, 'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'statement_opening_balance' => 0, 'statement_closing_balance' => 0, 'book_balance' => 0, 'difference' => 0, 'status' => 'draft', 'idempotency_key' => fake()->uuid(), 'idempotency_hash' => fake()->sha256(), 'created_by' => User::factory()];
    }
}

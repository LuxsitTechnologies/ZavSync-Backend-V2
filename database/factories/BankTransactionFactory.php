<?php

namespace Database\Factories;

use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'financial_account_id' => FinancialAccount::factory(), 'bank_statement_import_id' => null, 'evidence_type' => 'statement', 'transaction_date' => now()->toDateString(), 'value_date' => null, 'description' => fake()->sentence(), 'bank_reference' => fake()->unique()->bothify('REF-####'), 'external_transaction_id' => null, 'direction' => 'credit', 'amount' => fake()->numberBetween(100, 100000), 'running_balance' => null, 'currency' => 'PKR', 'counterparty_name' => fake()->company(), 'counterparty_account' => null, 'fingerprint' => fake()->unique()->sha256(), 'status' => 'unmatched', 'classification_journal_id' => null, 'origin_idempotency_key' => null, 'origin_idempotency_hash' => null, 'created_by' => User::factory()];
    }
}

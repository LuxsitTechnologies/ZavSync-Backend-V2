<?php

namespace Database\Factories;

use App\Models\BankReconciliationMatch;
use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankReconciliationMatch>
 */
class BankReconciliationMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'bank_transaction_id' => BankTransaction::factory(), 'bank_reconciliation_id' => null, 'matchable_type' => 'journal', 'matchable_id' => Journal::factory(), 'amount' => 1000, 'confidence' => 'manual', 'reason' => 'Factory match', 'status' => 'active', 'idempotency_key' => fake()->uuid(), 'idempotency_hash' => fake()->sha256(), 'matched_by' => User::factory(), 'matched_at' => now()];
    }
}

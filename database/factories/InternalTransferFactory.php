<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InternalTransfer;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternalTransfer>
 */
class InternalTransferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 100000);

        return ['company_id' => Company::factory(), 'sequence' => $sequence, 'number' => 'TRF-2026-'.$sequence, 'source_financial_account_id' => FinancialAccount::factory(), 'destination_financial_account_id' => FinancialAccount::factory(), 'transfer_date' => now()->toDateString(), 'amount' => fake()->numberBetween(100, 100000), 'currency' => 'PKR', 'reference' => fake()->uuid(), 'notes' => null, 'journal_id' => Journal::factory(), 'idempotency_key' => fake()->uuid(), 'idempotency_hash' => fake()->sha256(), 'created_by' => User::factory()];
    }
}

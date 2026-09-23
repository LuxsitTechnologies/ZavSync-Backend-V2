<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\GatewaySettlement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewaySettlement>
 */
class GatewaySettlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'provider' => 'JazzCash', 'settlement_reference' => fake()->unique()->bothify('SET-####'), 'settlement_date' => now()->toDateString(), 'gross_amount' => 100000, 'fee_amount' => 3000, 'adjustment_amount' => 0, 'net_amount' => 97000, 'currency' => 'PKR', 'destination_financial_account_id' => FinancialAccount::factory(), 'clearing_account_id' => Account::factory(), 'fee_account_id' => Account::factory()->expense(), 'status' => 'draft', 'journal_id' => null, 'idempotency_key' => fake()->uuid(), 'idempotency_hash' => fake()->sha256(), 'created_by' => User::factory()];
    }
}

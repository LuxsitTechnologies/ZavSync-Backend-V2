<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Journal;
use App\Models\PayrollBatch;
use App\Models\PayrollPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollPayment>
 */
class PayrollPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'sequence' => fake()->unique()->numberBetween(1, 999999), 'number' => fake()->unique()->bothify('PP-2026-####'), 'payroll_batch_id' => PayrollBatch::factory(), 'financial_account_id' => FinancialAccount::factory(), 'payment_date' => '2026-09-30', 'amount' => 100_000_00, 'currency' => 'PKR', 'reference' => null, 'notes' => null, 'journal_id' => Journal::factory(), 'idempotency_key' => fake()->unique()->uuid(), 'idempotency_hash' => hash('sha256', fake()->uuid()), 'created_by' => User::factory()];
    }
}

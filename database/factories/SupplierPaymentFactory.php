<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Company;
use App\Models\Journal;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierPayment>
 */
class SupplierPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'supplier_id' => Supplier::factory(), 'sequence' => fake()->unique()->numberBetween(1, 999999),
            'number' => fake()->unique()->bothify('PAY-2026-####'), 'payment_date' => '2026-09-23', 'posting_date' => '2026-09-23',
            'amount' => 50000, 'method' => 'bank_transfer', 'bank_account_id' => Account::factory(), 'reference' => null, 'notes' => null,
            'idempotency_key' => fake()->uuid(), 'idempotency_hash' => hash('sha256', fake()->uuid()),
            'journal_id' => Journal::factory(), 'created_by' => User::factory(),
        ];
    }
}

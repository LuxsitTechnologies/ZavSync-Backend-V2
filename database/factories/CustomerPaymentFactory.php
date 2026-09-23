<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Invoice;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerPayment>
 */
class CustomerPaymentFactory extends Factory
{
    protected $model = CustomerPayment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'company_id' => Company::factory(), 'customer_id' => Customer::factory(), 'invoice_id' => Invoice::factory(),
            'sequence' => $sequence, 'number' => sprintf('RCPT-2026-%06d', $sequence), 'payment_date' => '2026-09-22',
            'amount' => 50000, 'method' => PaymentMethod::BankTransfer, 'bank_account_id' => Account::factory(),
            'reference' => fake()->bothify('TRX-####'), 'notes' => null, 'idempotency_key' => fake()->uuid(),
            'idempotency_hash' => hash('sha256', fake()->uuid()), 'journal_id' => Journal::factory(), 'created_by' => User::factory(),
        ];
    }
}

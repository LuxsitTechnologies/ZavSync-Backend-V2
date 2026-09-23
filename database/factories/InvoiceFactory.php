<?php

namespace Database\Factories;

use App\Enums\FbrSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 999999);

        return [
            'company_id' => Company::factory(), 'customer_id' => Customer::factory(), 'sequence' => $sequence,
            'invoice_number' => sprintf('INV-2026-%06d', $sequence), 'invoice_date' => '2026-09-22', 'due_date' => '2026-10-22',
            'currency' => 'PKR', 'status' => InvoiceStatus::Draft, 'subtotal' => 100000, 'discount' => 0,
            'taxable_amount' => 100000, 'sales_tax' => 18000, 'other_tax' => 0, 'advance_tax' => 0,
            'withholding_tax' => 0, 'total' => 118000, 'amount_paid' => 0, 'balance_due' => 118000,
            'notes' => null, 'terms' => null, 'fbr_status' => FbrSubmissionStatus::NotSubmitted,
            'fbr_reference_number' => null, 'fbr_response_metadata' => null, 'creation_idempotency_key' => fake()->uuid(),
            'creation_idempotency_hash' => hash('sha256', fake()->uuid()), 'journal_id' => null, 'reversal_journal_id' => null,
            'created_by' => User::factory(), 'updated_by' => null, 'posted_by' => null, 'posted_at' => null, 'voided_by' => null, 'voided_at' => null,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (): array => ['status' => InvoiceStatus::Unpaid, 'posted_at' => now()]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\SupplierBillStatus;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierBill>
 */
class SupplierBillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'supplier_id' => Supplier::factory(), 'purchase_order_id' => null, 'purchase_receipt_id' => null,
            'sequence' => fake()->unique()->numberBetween(1, 999999), 'bill_number' => fake()->unique()->bothify('BILL-2026-####'),
            'supplier_invoice_number' => fake()->unique()->bothify('SI-#####'), 'bill_date' => '2026-09-22', 'posting_date' => '2026-09-22',
            'due_date' => '2026-10-22', 'currency' => 'PKR', 'status' => SupplierBillStatus::Draft,
            'subtotal' => 100000, 'discount' => 0, 'taxable_amount' => 100000, 'purchase_tax' => 18000,
            'withholding_tax' => 5000, 'gross_total' => 118000, 'total' => 113000, 'amount_paid' => 0, 'balance_due' => 113000,
            'notes' => null, 'creation_idempotency_key' => fake()->uuid(), 'creation_idempotency_hash' => hash('sha256', fake()->uuid()),
            'journal_id' => null, 'reversal_journal_id' => null, 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}

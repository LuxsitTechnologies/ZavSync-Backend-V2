<?php

namespace Database\Factories;

use App\Enums\PurchaseReceiptStatus;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseReceipt>
 */
class PurchaseReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'purchase_order_id' => PurchaseOrder::factory(), 'supplier_id' => Supplier::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 999999), 'number' => fake()->unique()->bothify('GRN-2026-####'),
            'receipt_date' => '2026-09-23', 'status' => PurchaseReceiptStatus::Partial, 'notes' => null,
            'idempotency_key' => fake()->uuid(), 'idempotency_hash' => hash('sha256', fake()->uuid()), 'received_by' => User::factory(),
        ];
    }
}

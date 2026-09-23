<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
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
            'number' => fake()->unique()->bothify('PO-2026-####'), 'order_date' => '2026-09-22', 'expected_delivery_date' => '2026-09-30',
            'currency' => 'PKR', 'status' => PurchaseOrderStatus::Draft, 'reference' => null, 'notes' => null,
            'subtotal' => 100000, 'discount' => 0, 'taxable_amount' => 100000, 'tax' => 18000, 'total' => 118000,
            'creation_idempotency_key' => fake()->uuid(), 'creation_idempotency_hash' => hash('sha256', fake()->uuid()),
            'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(), 'position' => 1, 'item_id' => null, 'item_name' => 'Purchased service',
            'description' => 'Purchased service', 'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit',
            'unit_price' => 100000, 'subtotal' => 100000, 'discount' => 0, 'taxable_amount' => 100000,
            'tax_rate_bps' => 1800, 'tax_amount' => 18000, 'total' => 118000, 'expense_account_id' => null,
            'received_quantity_milli' => 0, 'billed_quantity_milli' => 0, 'metadata' => null,
        ];
    }
}

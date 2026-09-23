<?php

namespace Database\Factories;

use App\Models\PurchaseOrderLine;
use App\Models\PurchaseReceipt;
use App\Models\PurchaseReceiptLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseReceiptLine>
 */
class PurchaseReceiptLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_receipt_id' => PurchaseReceipt::factory(), 'purchase_order_line_id' => PurchaseOrderLine::factory(),
            'ordered_quantity_milli' => 1000, 'previously_received_quantity_milli' => 0,
            'quantity_received_milli' => 500, 'remaining_quantity_milli' => 500,
        ];
    }
}

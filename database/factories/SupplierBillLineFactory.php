<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\SupplierBill;
use App\Models\SupplierBillLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierBillLine>
 */
class SupplierBillLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_bill_id' => SupplierBill::factory(), 'purchase_order_line_id' => null, 'purchase_receipt_line_id' => null,
            'position' => 1, 'item_id' => null, 'item_name' => 'Purchased service', 'description' => 'Purchased service',
            'procurement_type' => 'service', 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000,
            'subtotal' => 100000, 'discount' => 0, 'taxable_amount' => 100000, 'tax_rate_bps' => 1800,
            'tax_amount' => 18000, 'withholding_rate_bps' => 500, 'withholding_amount' => 5000,
            'total' => 113000, 'expense_account_id' => Account::factory()->expense(), 'metadata' => null,
        ];
    }
}

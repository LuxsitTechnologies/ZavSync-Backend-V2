<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(), 'position' => 1, 'item_id' => null, 'item_name' => 'Professional services',
            'description' => fake()->sentence(), 'quantity_milli' => 1000, 'unit' => 'unit', 'unit_price' => 100000,
            'subtotal' => 100000, 'discount' => 0, 'taxable_amount' => 100000, 'tax_rate_bps' => 1800,
            'tax_amount' => 18000, 'other_tax_rate_bps' => 0, 'other_tax_amount' => 0, 'advance_tax_rate_bps' => 0,
            'advance_tax_amount' => 0, 'withholding_tax_rate_bps' => 0, 'withholding_tax_amount' => 0,
            'total' => 118000, 'sales_type' => 'Standardized Goods', 'tax_metadata' => null,
        ];
    }
}

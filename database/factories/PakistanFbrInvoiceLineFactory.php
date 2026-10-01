<?php

namespace Database\Factories;

use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PakistanFbrInvoiceLine> */
class PakistanFbrInvoiceLineFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => PakistanFbrInvoice::factory(), 'position' => 1, 'description' => 'Fixture service',
            'hs_code' => '9983.0000', 'unit' => 'unit', 'quantity_milli' => 1000, 'unit_price' => 10000,
            'subtotal' => 10000, 'taxable_amount' => 10000, 'tax_rate_bps' => 1800, 'fbr_rate_id' => '18%',
            'tax_amount' => 1800, 'total' => 11800, 'sales_type' => 'Goods at standard rate (default)',
        ];
    }
}

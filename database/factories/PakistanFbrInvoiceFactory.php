<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PakistanFbrInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PakistanFbrInvoice> */
class PakistanFbrInvoiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'created_by' => User::factory(), 'document_state' => 'DRAFT',
            'invoice_number' => 'PKF-'.fake()->unique()->numerify('########'), 'invoice_date' => '2026-09-22',
            'due_date' => '2026-10-22', 'invoice_type' => 'Sale Invoice', 'sale_type' => 'Goods at standard rate (default)',
            'origin_province' => 'SINDH', 'destination_province' => 'SINDH',
            'buyer_snapshot' => ['registration_number' => '1234567', 'name' => 'Fixture Buyer', 'type' => 'Registered', 'province' => 'SINDH', 'address' => 'Karachi'],
            'subtotal' => 10000, 'taxable_amount' => 10000, 'sales_tax' => 1800, 'total' => 11800,
        ];
    }
}

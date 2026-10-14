<?php

namespace Database\Factories;

use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrCreditNoteLine;
use App\Models\PakistanFbrInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PakistanFbrCreditNoteLine>
 */
class PakistanFbrCreditNoteLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'credit_note_id' => PakistanFbrCreditNote::factory(),
            'invoice_line_id' => fn (array $attributes): string => PakistanFbrInvoiceLine::factory()
                ->for(PakistanFbrCreditNote::query()->findOrFail($attributes['credit_note_id'])->invoice, 'invoice')->create()->id,
            'source_snapshot' => [],
            'taxable_amount' => 1000, 'sales_tax' => 180, 'extra_tax' => 0,
            'further_tax' => 0, 'st_withheld' => 0, 'total' => 1180,
        ];
    }
}

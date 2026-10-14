<?php

namespace Database\Factories;

use App\Enums\FbrSubmissionStatus;
use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PakistanFbrCreditNote>
 */
class PakistanFbrCreditNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => PakistanFbrInvoice::factory()->state([
                'fbr_status' => FbrSubmissionStatus::Accepted, 'fbr_reference_number' => 'FBR-FACTORY-'.fake()->unique()->numerify('########'),
            ]),
            'company_id' => fn (array $attributes): string => PakistanFbrInvoice::query()->findOrFail($attributes['invoice_id'])->company_id,
            'sequence' => fake()->unique()->numberBetween(1, 1000000),
            'credit_note_number' => 'PKFC-FIXTURE-'.fake()->unique()->numerify('########'),
            'idempotency_key' => 'fixture-'.fake()->uuid(),
            'payload_hash' => hash('sha256', 'fixture-'.fake()->uuid()),
            'state' => 'DRAFT', 'issued_on' => now()->toDateString(),
            'original_invoice_number' => 'PKF-FIXTURE-'.fake()->numerify('########'),
            'original_fbr_reference_number' => 'FBR-FACTORY-'.fake()->numerify('########'),
            'original_fbr_evidence' => [], 'seller_snapshot' => [], 'buyer_snapshot' => [], 'portal_snapshot' => [],
            'reason' => 'Fixture customer price adjustment',
            'taxable_amount' => 1000, 'sales_tax' => 180, 'extra_tax' => 0,
            'further_tax' => 0, 'st_withheld' => 0, 'total' => 1180,
            'created_by' => User::factory(),
        ];
    }
}

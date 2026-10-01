<?php

namespace Database\Factories;

use App\Models\LegacyFbrEvidence;
use App\Models\LegacyImportRun;
use App\Models\PakistanFbrInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyFbrEvidence>
 */
class LegacyFbrEvidenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['invoice_id' => PakistanFbrInvoice::factory(), 'company_id' => fn (array $attributes): string => PakistanFbrInvoice::query()->findOrFail($attributes['invoice_id'])->company_id, 'import_run_id' => fn (array $attributes): string => LegacyImportRun::factory()->create(['company_id' => $attributes['company_id']])->id, 'source_system' => 'zavsync_v1', 'source_id' => (string) fake()->unique()->randomNumber(), 'original_status' => 'success', 'normalized_status' => 'accepted', 'fbr_reference_number' => fake()->unique()->numerify('FBR-########'), 'retry_count' => 0, 'last_attempt_at' => now(), 'source_created_at' => now(), 'source_updated_at' => now(), 'sanitized_response' => ['status' => 'success'], 'requires_review' => false, 'submission_blocked' => true];
    }
}

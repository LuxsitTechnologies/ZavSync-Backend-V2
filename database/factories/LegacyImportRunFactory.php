<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LegacyImportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyImportRun>
 */
class LegacyImportRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'source_system' => 'zavsync_v1', 'source_company_id' => '4', 'source_fingerprint' => hash('sha256', fake()->uuid()), 'status' => 'COMPLETED', 'mode' => 'IMPORT', 'source_filename' => 'fixture.json', 'source_manifest' => ['counts' => ['invoices' => 1]], 'progress' => ['invoices_processed' => 1, 'invoices_total' => 1], 'reconciliation' => ['unexplained_discrepancy_count' => 0], 'failure_message' => null, 'created_by' => User::factory(), 'started_at' => now(), 'completed_at' => now()];
    }
}

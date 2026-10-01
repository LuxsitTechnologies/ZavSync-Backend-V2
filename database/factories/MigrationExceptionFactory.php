<?php

namespace Database\Factories;

use App\Models\LegacyImportRun;
use App\Models\MigrationException;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MigrationException>
 */
class MigrationExceptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['import_run_id' => LegacyImportRun::factory(), 'company_id' => fn (array $attributes): string => LegacyImportRun::query()->findOrFail($attributes['import_run_id'])->company_id, 'source_entity_type' => 'invoice', 'source_id' => (string) fake()->randomNumber(), 'target_id' => null, 'exception_code' => 'FINANCIAL_TOTAL_MISMATCH', 'severity' => 'WARNING', 'safe_metadata' => ['difference_minor' => 1], 'resolution_state' => 'OPEN', 'resolution_note' => null, 'resolved_by' => null, 'resolved_at' => null];
    }
}

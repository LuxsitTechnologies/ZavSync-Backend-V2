<?php

namespace Database\Factories;

use App\Models\LegacyEntityMap;
use App\Models\LegacyImportRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LegacyEntityMap>
 */
class LegacyEntityMapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['import_run_id' => LegacyImportRun::factory(), 'company_id' => fn (array $attributes): string => LegacyImportRun::query()->findOrFail($attributes['import_run_id'])->company_id, 'source_system' => 'zavsync_v1', 'source_entity_type' => 'invoice', 'source_id' => (string) fake()->unique()->randomNumber(), 'target_entity_type' => null, 'target_id' => null, 'safe_metadata' => []];
    }
}

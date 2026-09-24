<?php

namespace Database\Factories;

use App\Models\CrmImport;
use App\Models\CrmImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmImportRow>
 */
class CrmImportRowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'crm_import_id' => CrmImport::factory(),
            'company_id' => fn (array $attributes): string => (string) CrmImport::query()->findOrFail($attributes['crm_import_id'])->company_id,
            'row_number' => fake()->unique()->numberBetween(1, 100000), 'source_data' => ['Name' => fake()->name()],
            'mapped_data' => ['first_name' => fake()->firstName()], 'state' => 'CREATE', 'errors' => null,
            'warnings' => null, 'created_record_type' => null, 'created_record_id' => null,
        ];
    }
}

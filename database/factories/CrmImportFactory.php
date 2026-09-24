<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmImport>
 */
class CrmImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'entity_type' => 'LEAD', 'original_filename' => 'leads.csv',
            'status' => 'PREVIEWED', 'mapping' => ['Name' => 'first_name'],
            'summary' => ['total' => 1, 'valid' => 1, 'invalid' => 0, 'duplicates' => 0],
            'file_hash' => hash('sha256', fake()->uuid()), 'idempotency_key' => fake()->unique()->uuid(),
            'confirmed_at' => null, 'created_by' => User::factory(),
        ];
    }
}

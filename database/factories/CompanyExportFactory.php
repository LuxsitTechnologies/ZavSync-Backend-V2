<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyExport>
 */
class CompanyExportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'requested_by' => User::factory(), 'status' => 'PENDING',
            'sections' => ['settings', 'users'], 'storage_disk' => 'local',
        ];
    }
}

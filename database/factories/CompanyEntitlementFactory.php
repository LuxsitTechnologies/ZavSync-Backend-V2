<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyEntitlement;
use App\Models\PlatformModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyEntitlement>
 */
class CompanyEntitlementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'module_key' => PlatformModule::factory(), 'is_enabled' => true, 'limits' => null,
        ];
    }
}

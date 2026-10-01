<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FbrCompanyConfiguration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbrCompanyConfiguration>
 */
class FbrCompanyConfigurationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'seller_tax_identifier' => '1234567', 'seller_business_name' => fake()->company(), 'seller_province' => 'SINDH', 'seller_address' => fake()->address(), 'environment' => 'SANDBOX', 'credential' => 'test-credential-not-a-secret', 'connection_state' => 'NOT_VERIFIED', 'last_verified_at' => null, 'last_error' => null, 'updated_by' => User::factory()];
    }
}

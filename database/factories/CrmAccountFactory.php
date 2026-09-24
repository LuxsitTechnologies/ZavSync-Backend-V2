<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmAccount>
 */
class CrmAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'owner_id' => null, 'customer_id' => null,
            'name' => fake()->company(), 'legal_name' => fake()->company(), 'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(), 'website' => fake()->url(), 'ntn' => fake()->numerify('#######'),
            'cnic' => null, 'registration_number' => fake()->bothify('REG-####'), 'industry' => fake()->randomElement(['Technology', 'Manufacturing', 'Retail']),
            'account_type' => 'BUSINESS', 'address' => fake()->address(), 'city' => fake()->city(), 'country' => 'PK',
            'postal_code' => fake()->postcode(), 'source' => 'Referral', 'status' => 'PROSPECT', 'notes' => null,
            'is_archived' => false, 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['is_archived' => true, 'status' => 'INACTIVE']);
    }
}

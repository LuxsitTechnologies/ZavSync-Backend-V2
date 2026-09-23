<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'sequence' => fake()->unique()->numberBetween(1, 999999),
            'code' => fake()->unique()->bothify('SUP-####'), 'name' => fake()->company(), 'legal_name' => fake()->company(),
            'contact_person' => fake()->name(), 'email' => fake()->companyEmail(), 'phone' => fake()->phoneNumber(),
            'billing_address' => fake()->address(), 'city' => fake()->city(), 'province' => 'Punjab', 'country' => 'PK',
            'postal_code' => fake()->postcode(), 'ntn' => null, 'cnic' => null, 'strn' => null, 'tax_status' => 'registered',
            'payment_terms_days' => 30, 'currency' => 'PKR', 'default_expense_account_id' => null,
            'default_payable_account_id' => null, 'is_active' => true, 'notes' => null, 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}

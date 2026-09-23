<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'sequence' => fake()->unique()->numberBetween(1, 999999), 'code' => fake()->unique()->bothify('CUS-####'), 'name' => fake()->company(),
            'legal_name' => fake()->company(), 'type' => 'business', 'ntn' => fake()->numerify('#######'), 'cnic' => null,
            'strn' => null, 'email' => fake()->companyEmail(), 'phone' => fake()->phoneNumber(), 'billing_address' => fake()->address(),
            'city' => fake()->city(), 'province' => 'Punjab', 'country' => 'PK', 'postal_code' => fake()->postcode(),
            'contact_person' => fake()->name(), 'payment_terms_days' => 30, 'credit_limit' => null, 'currency' => 'PKR',
            'tax_metadata' => null, 'is_active' => true, 'notes' => null, 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }

    public function individual(): static
    {
        return $this->state(fn (): array => ['type' => 'individual', 'ntn' => null, 'cnic' => fake()->numerify('#############')]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}

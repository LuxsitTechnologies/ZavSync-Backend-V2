<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmContact>
 */
class CrmContactFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'account_id' => null, 'owner_id' => null,
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'job_title' => fake()->jobTitle(),
            'department' => fake()->randomElement(['Finance', 'Operations', 'Sales']), 'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(), 'mobile' => fake()->phoneNumber(), 'is_primary' => false,
            'address' => fake()->address(), 'notes' => null, 'status' => 'ACTIVE', 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmLead>
 */
class CrmLeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'account_id' => null, 'contact_id' => null, 'owner_id' => null,
            'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(), 'company_name' => fake()->company(),
            'job_title' => fake()->jobTitle(), 'email' => fake()->unique()->safeEmail(), 'phone' => fake()->phoneNumber(),
            'mobile' => null, 'website' => fake()->url(), 'source' => fake()->randomElement(['Website', 'Referral', 'Conference']),
            'status' => 'NEW', 'estimated_value' => fake()->numberBetween(100_000, 50_000_000), 'currency' => 'PKR',
            'expected_timeframe' => fake()->dateTimeBetween('now', '+6 months')->format('Y-m-d'), 'interest' => 'ZavSync platform',
            'notes' => null, 'score' => 0, 'qualification_notes' => null, 'converted_at' => null,
            'converted_account_id' => null, 'converted_contact_id' => null, 'converted_deal_id' => null,
            'created_by' => User::factory(), 'updated_by' => null,
        ];
    }

    public function qualified(): static
    {
        return $this->state(fn (): array => ['status' => 'QUALIFIED']);
    }
}

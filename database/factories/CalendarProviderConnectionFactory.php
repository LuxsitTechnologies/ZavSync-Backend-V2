<?php

namespace Database\Factories;

use App\Models\CalendarProviderConnection;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarProviderConnection>
 */
class CalendarProviderConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'user_id' => User::factory(), 'provider' => 'google', 'status' => 'NOT_CONFIGURED', 'identity_email' => fake()->safeEmail(), 'sync_metadata' => [], 'created_by' => User::factory()];
    }
}

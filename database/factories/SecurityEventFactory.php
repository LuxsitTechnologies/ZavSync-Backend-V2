<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityEvent>
 */
class SecurityEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'user_id' => User::factory(), 'type' => 'LOGIN_SUCCEEDED',
            'result' => 'SUCCESS', 'ip_address' => fake()->ipv4(), 'user_agent' => fake()->userAgent(), 'correlation_id' => fake()->uuid(),
        ];
    }
}

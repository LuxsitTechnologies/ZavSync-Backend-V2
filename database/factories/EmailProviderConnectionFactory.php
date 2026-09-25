<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmailProviderConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailProviderConnection>
 */
class EmailProviderConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'name' => fake()->unique()->company().' SMTP', 'provider_type' => 'SMTP', 'status' => 'CONNECTED', 'configuration' => ['host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'sender@example.test'], 'credentials' => ['password' => 'test-secret'], 'last_verified_at' => now(), 'created_by' => User::factory()];
    }
}

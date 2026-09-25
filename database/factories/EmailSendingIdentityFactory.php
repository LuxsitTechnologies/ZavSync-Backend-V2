<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailSendingIdentity>
 */
class EmailSendingIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'provider_connection_id' => EmailProviderConnection::factory(), 'from_email' => fake()->unique()->safeEmail(), 'from_name' => fake()->company(), 'verification_status' => 'VERIFIED', 'is_default' => false, 'is_active' => true, 'verified_at' => now(), 'created_by' => User::factory()];
    }
}

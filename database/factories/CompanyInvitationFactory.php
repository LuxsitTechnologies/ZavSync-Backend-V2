<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyInvitation>
 */
class CompanyInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', fake()->unique()->uuid()), 'status' => 'PENDING', 'role_ids' => [],
            'invited_by' => User::factory(), 'expires_at' => now()->addDays(7),
        ];
    }
}

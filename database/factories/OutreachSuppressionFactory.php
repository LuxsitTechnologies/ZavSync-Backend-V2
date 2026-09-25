<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OutreachSuppression;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachSuppression>
 */
class OutreachSuppressionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return ['company_id' => Company::factory(), 'email' => $email, 'normalized_email' => mb_strtolower($email), 'reason' => 'MANUAL', 'source' => 'MANUAL', 'is_active' => true, 'suppressed_at' => now(), 'created_by' => User::factory()];
    }
}

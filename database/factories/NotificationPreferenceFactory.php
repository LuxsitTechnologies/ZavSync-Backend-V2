<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'user_id' => User::factory(), 'type' => 'test.'.fake()->unique()->slug(2),
            'in_app_enabled' => true, 'email_enabled' => true,
        ];
    }
}

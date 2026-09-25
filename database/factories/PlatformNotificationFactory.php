<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformNotification>
 */
class PlatformNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'recipient_id' => User::factory(), 'type' => 'invoice.updated',
            'channel' => 'IN_APP', 'title' => fake()->sentence(4), 'message' => fake()->sentence(), 'delivery_state' => 'PENDING',
        ];
    }
}

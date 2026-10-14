<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InternalCalendarEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InternalCalendarEvent>
 */
class InternalCalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'organizer_user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => null,
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'timezone' => 'Asia/Karachi',
            'status' => 'ACTIVE',
            'version' => 1,
        ];
    }
}

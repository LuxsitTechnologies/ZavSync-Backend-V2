<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\CalendarProviderConnection;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'calendar_provider_connection_id' => CalendarProviderConnection::factory(), 'external_event_id' => fake()->unique()->uuid(), 'title' => fake()->sentence(4), 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'status' => 'CONFIRMED', 'attendees' => [], 'sync_metadata' => [], 'synced_at' => now()];
    }
}

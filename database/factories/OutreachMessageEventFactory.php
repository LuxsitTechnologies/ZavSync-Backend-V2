<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OutreachMessage;
use App\Models\OutreachMessageEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachMessageEvent>
 */
class OutreachMessageEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'message_id' => OutreachMessage::factory(), 'provider_event_id' => fake()->unique()->uuid(), 'type' => 'DELIVERED', 'occurred_at' => now(), 'payload' => [], 'processed_at' => now()];
    }
}

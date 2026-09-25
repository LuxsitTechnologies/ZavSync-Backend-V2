<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OutreachMessage;
use App\Models\OutreachSendAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachSendAttempt>
 */
class OutreachSendAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'message_id' => OutreachMessage::factory(), 'attempt_number' => 1, 'idempotency_key' => fake()->unique()->uuid(), 'status' => 'SUCCEEDED', 'provider_message_id' => fake()->uuid(), 'started_at' => now(), 'completed_at' => now()];
    }
}

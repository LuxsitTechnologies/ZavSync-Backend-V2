<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OutreachMessage;
use App\Models\OutreachMessageLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachMessageLink>
 */
class OutreachMessageLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'message_id' => OutreachMessage::factory(), 'token_hash' => hash('sha256', fake()->unique()->uuid()), 'destination_url' => fake()->url(), 'click_count' => 0];
    }
}

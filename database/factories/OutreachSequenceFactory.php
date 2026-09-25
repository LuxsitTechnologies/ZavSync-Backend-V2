<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmailSendingIdentity;
use App\Models\OutreachSequence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachSequence>
 */
class OutreachSequenceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'sending_identity_id' => EmailSendingIdentity::factory(), 'owner_id' => null, 'name' => fake()->unique()->words(3, true), 'status' => 'DRAFT', 'timezone' => 'Asia/Karachi', 'allowed_weekdays' => [1, 2, 3, 4, 5], 'send_window_start' => '09:00', 'send_window_end' => '17:00', 'track_opens' => true, 'track_clicks' => true, 'stop_on_reply' => true, 'created_by' => User::factory()];
    }
}

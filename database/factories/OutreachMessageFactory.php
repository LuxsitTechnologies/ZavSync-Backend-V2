<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\EmailProviderConnection;
use App\Models\EmailSendingIdentity;
use App\Models\OutreachEnrollment;
use App\Models\OutreachMessage;
use App\Models\OutreachSequence;
use App\Models\OutreachSequenceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachMessage>
 */
class OutreachMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'enrollment_id' => OutreachEnrollment::factory(), 'sequence_id' => OutreachSequence::factory(), 'sequence_step_id' => OutreachSequenceStep::factory(), 'sending_identity_id' => EmailSendingIdentity::factory(), 'provider_connection_id' => EmailProviderConnection::factory(), 'stable_message_id' => fake()->unique()->uuid().'@zavsync.local', 'recipient_type' => 'CONTACT', 'recipient_id' => fake()->uuid(), 'to_email' => fake()->safeEmail(), 'to_name' => fake()->name(), 'from_email' => fake()->safeEmail(), 'from_name' => fake()->company(), 'subject' => fake()->sentence(), 'body_text' => fake()->paragraph(), 'state' => 'SCHEDULED', 'scheduled_at' => now(), 'unsubscribe_token_hash' => hash('sha256', fake()->uuid()), 'tracking_token_hash' => hash('sha256', fake()->uuid())];
    }
}

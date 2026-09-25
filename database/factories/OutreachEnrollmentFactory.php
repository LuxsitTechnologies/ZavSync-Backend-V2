<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmContact;
use App\Models\OutreachEnrollment;
use App\Models\OutreachSequence;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachEnrollment>
 */
class OutreachEnrollmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $key = fake()->unique()->uuid();

        return ['company_id' => Company::factory(), 'sequence_id' => OutreachSequence::factory(), 'recipient_type' => 'CONTACT', 'recipient_id' => CrmContact::factory(), 'recipient_email' => fake()->unique()->safeEmail(), 'recipient_name' => fake()->name(), 'status' => 'ACTIVE', 'current_step_position' => 1, 'next_action_at' => now(), 'idempotency_key' => $key, 'idempotency_hash' => hash('sha256', $key), 'enrolled_at' => now(), 'created_by' => User::factory()];
    }
}

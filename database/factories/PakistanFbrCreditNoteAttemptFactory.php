<?php

namespace Database\Factories;

use App\Enums\FbrSubmissionStatus;
use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrCreditNoteAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PakistanFbrCreditNoteAttempt>
 */
class PakistanFbrCreditNoteAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes): string => PakistanFbrCreditNote::query()->findOrFail($attributes['credit_note_id'])->company_id,
            'credit_note_id' => PakistanFbrCreditNote::factory(),
            'idempotency_key' => 'fixture-'.fake()->uuid(),
            'payload_hash' => hash('sha256', fake()->uuid()),
            'claim_generation' => 1,
            'status' => FbrSubmissionStatus::Pending,
            'submitted_by' => User::factory(),
        ];
    }
}

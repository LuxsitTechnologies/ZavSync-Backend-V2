<?php

namespace Database\Factories;

use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PakistanFbrSubmissionAttempt> */
class PakistanFbrSubmissionAttemptFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => PakistanFbrInvoice::factory(),
            'company_id' => fn (array $attributes): string => PakistanFbrInvoice::query()->findOrFail($attributes['invoice_id'])->company_id,
            'idempotency_key' => fake()->uuid(), 'payload_hash' => hash('sha256', 'fixture'),
            'status' => 'pending', 'submitted_by' => User::factory(),
        ];
    }
}

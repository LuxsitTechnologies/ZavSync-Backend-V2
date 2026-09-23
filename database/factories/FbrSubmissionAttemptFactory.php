<?php

namespace Database\Factories;

use App\Enums\FbrSubmissionStatus;
use App\Models\Company;
use App\Models\FbrSubmissionAttempt;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FbrSubmissionAttempt>
 */
class FbrSubmissionAttemptFactory extends Factory
{
    protected $model = FbrSubmissionAttempt::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'invoice_id' => Invoice::factory(), 'idempotency_key' => fake()->uuid(),
            'payload_hash' => hash('sha256', fake()->uuid()), 'status' => FbrSubmissionStatus::Pending,
            'request_metadata' => ['line_count' => 1], 'response_metadata' => null, 'reference_number' => null,
            'error_message' => null, 'submitted_by' => User::factory(), 'completed_at' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OperationalPrioritySignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalPrioritySignal>
 */
class OperationalPrioritySignalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'category' => 'RECEIVABLES', 'source_module' => 'receivables', 'source_type' => 'invoice', 'source_id' => fake()->uuid(), 'title' => 'Overdue receivable', 'description' => 'A material invoice is overdue.', 'severity' => 'HIGH', 'priority_score' => 7600, 'confidence_bps' => 10000, 'status' => 'OPEN', 'fingerprint' => fake()->unique()->sha256(), 'supporting_metrics' => ['amount_minor' => 100000], 'score_breakdown' => ['materiality' => 2500, 'overdue' => 2500, 'severity' => 2000, 'deadline' => 600], 'explanation_metadata' => [], 'related_url' => '/accounting/receivables', 'detected_at' => now(), 'effective_at' => now(), 'last_seen_at' => now()];
    }
}

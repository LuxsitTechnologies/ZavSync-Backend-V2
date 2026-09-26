<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OperationalPrioritySignal;
use App\Models\OperationalSignalEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperationalSignalEvent>
 */
class OperationalSignalEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'operational_priority_signal_id' => OperationalPrioritySignal::factory(), 'event_type' => 'DETECTED', 'from_status' => null, 'to_status' => 'OPEN', 'metadata' => []];
    }
}

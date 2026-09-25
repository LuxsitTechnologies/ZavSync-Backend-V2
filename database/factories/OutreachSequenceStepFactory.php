<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\OutreachSequence;
use App\Models\OutreachSequenceStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutreachSequenceStep>
 */
class OutreachSequenceStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'sequence_id' => OutreachSequence::factory(), 'position' => fake()->unique()->numberBetween(1, 60000), 'type' => 'EMAIL', 'subject' => 'A message for {{contact.first_name}}', 'body_text' => 'Hello {{contact.first_name}}.', 'wait_minutes' => 0];
    }
}

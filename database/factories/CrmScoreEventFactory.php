<?php

namespace Database\Factories;

use App\Models\CrmLead;
use App\Models\CrmScoreEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmScoreEvent>
 */
class CrmScoreEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scoreable_type' => CrmLead::class, 'scoreable_id' => CrmLead::factory(),
            'company_id' => fn (array $attributes): string => (string) CrmLead::query()->findOrFail($attributes['scoreable_id'])->company_id,
            'score_rule_id' => null, 'points' => 10, 'reason' => 'Contact email is present',
            'details' => ['field' => 'email'], 'calculated_at' => now(), 'created_by' => User::factory(),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmActivity>
 */
class CrmActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'activityable_type' => null, 'activityable_id' => null,
            'owner_id' => null, 'type' => 'TASK', 'subject' => fake()->sentence(4), 'description' => fake()->sentence(),
            'due_at' => fake()->dateTimeBetween('now', '+2 weeks'), 'completed_at' => null, 'status' => 'PENDING',
            'priority' => 'MEDIUM', 'outcome' => null, 'created_by' => User::factory(), 'updated_by' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => 'COMPLETED', 'completed_at' => now()]);
    }
}

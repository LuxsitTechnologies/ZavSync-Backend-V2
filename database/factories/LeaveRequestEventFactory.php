<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequestEvent>
 */
class LeaveRequestEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(),
            'leave_request_id' => fn (array $attributes): string => LeaveRequest::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'action' => 'SUBMITTED', 'from_status' => null, 'to_status' => 'PENDING',
            'reason' => null, 'actor_id' => User::factory(), 'created_at' => now()];
    }
}

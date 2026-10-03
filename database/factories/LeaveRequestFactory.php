<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(),
            'employee_id' => fn (array $attributes): string => Employee::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'leave_type_id' => fn (array $attributes): string => LeaveType::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create(['name' => 'Unpaid Leave', 'is_paid' => false])->id,
            'leave_entitlement_id' => null, 'type_name_snapshot' => 'Unpaid Leave', 'is_paid_snapshot' => false,
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(),
            'day_portion' => 'FULL_DAY', 'units' => 2, 'status' => 'PENDING', 'reason' => 'Personal leave request',
            'request_key_hash' => hash('sha256', fake()->uuid()), 'payload_hash' => hash('sha256', fake()->uuid()), 'submitted_by' => User::factory()];
    }
}

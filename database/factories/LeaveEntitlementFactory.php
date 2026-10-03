<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveEntitlement;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveEntitlement>
 */
class LeaveEntitlementFactory extends Factory
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
            'leave_type_id' => fn (array $attributes): string => LeaveType::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'year' => now()->year, 'allocated_units' => 40, 'created_by' => User::factory()];
    }
}

<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeTeam;
use App\Models\EmployeeTeamMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTeamMembership>
 */
class EmployeeTeamMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['employee_team_id' => EmployeeTeam::factory(),
            'company_id' => fn (array $attributes): string => EmployeeTeam::query()->findOrFail($attributes['employee_team_id'])->company_id,
            'employee_id' => fn (array $attributes): string => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'created_by' => User::factory(), 'is_active' => true];
    }
}

<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeShiftAssignment>
 */
class EmployeeShiftAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_rota_slot_id' => EmployeeRotaSlot::factory(),
            'company_id' => fn (array $attributes): string => EmployeeRotaSlot::query()->findOrFail($attributes['employee_rota_slot_id'])->company_id,
            'employee_id' => fn (array $attributes): string => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'created_by' => User::factory(),
        ];
    }
}

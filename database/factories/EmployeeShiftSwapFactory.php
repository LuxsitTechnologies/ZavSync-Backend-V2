<?php

namespace Database\Factories;

use App\Models\EmployeeRota;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShiftAssignment;
use App\Models\EmployeeShiftSwap;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeShiftSwap>
 */
class EmployeeShiftSwapFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'from_assignment_id' => EmployeeShiftAssignment::factory(),
            'company_id' => fn (array $attributes): string => EmployeeShiftAssignment::query()->findOrFail($attributes['from_assignment_id'])->company_id,
            'requester_employee_id' => fn (array $attributes): string => EmployeeShiftAssignment::query()->findOrFail($attributes['from_assignment_id'])->employee_id,
            'to_assignment_id' => function (array $attributes): string {
                $rota = EmployeeRota::factory()->create(['company_id' => $attributes['company_id']]);
                $slot = EmployeeRotaSlot::factory()->create(['company_id' => $attributes['company_id'], 'employee_rota_id' => $rota->id]);

                return EmployeeShiftAssignment::factory()->create(['company_id' => $attributes['company_id'],
                    'employee_rota_slot_id' => $slot->id])->id;
            },
            'target_employee_id' => fn (array $attributes): string => EmployeeShiftAssignment::query()->findOrFail($attributes['to_assignment_id'])->employee_id,
            'from_assignment_version' => 1,
            'to_assignment_version' => 1,
            'reason' => 'Schedule exchange',
            'created_by' => User::factory(),
        ];
    }
}

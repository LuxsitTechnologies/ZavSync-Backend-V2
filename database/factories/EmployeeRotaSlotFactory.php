<?php

namespace Database\Factories;

use App\Models\EmployeeRota;
use App\Models\EmployeeRotaSlot;
use App\Models\EmployeeShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeRotaSlot>
 */
class EmployeeRotaSlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_rota_id' => EmployeeRota::factory(),
            'company_id' => fn (array $attributes): string => EmployeeRota::query()->findOrFail($attributes['employee_rota_id'])->company_id,
            'employee_shift_id' => fn (array $attributes): string => EmployeeShift::factory()->create(['company_id' => $attributes['company_id']])->id,
            'shift_date' => fn (array $attributes): string => EmployeeRota::query()->findOrFail($attributes['employee_rota_id'])->start_date->toDateString(),
            'start_time' => '09:00',
            'end_time' => '17:00',
            'required_coverage' => 1,
            'created_by' => User::factory(),
        ];
    }
}

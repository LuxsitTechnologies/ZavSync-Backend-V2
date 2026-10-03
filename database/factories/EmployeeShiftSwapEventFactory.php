<?php

namespace Database\Factories;

use App\Models\EmployeeShiftSwap;
use App\Models\EmployeeShiftSwapEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeShiftSwapEvent>
 */
class EmployeeShiftSwapEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_shift_swap_id' => EmployeeShiftSwap::factory(),
            'company_id' => fn (array $attributes): string => EmployeeShiftSwap::query()->findOrFail($attributes['employee_shift_swap_id'])->company_id,
            'event_type' => 'REQUESTED',
            'swap_version' => 1,
            'actor_id' => User::factory(),
            'occurred_at' => now(),
        ];
    }
}

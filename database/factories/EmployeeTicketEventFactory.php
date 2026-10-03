<?php

namespace Database\Factories;

use App\Models\EmployeeTicket;
use App\Models\EmployeeTicketEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTicketEvent>
 */
class EmployeeTicketEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_ticket_id' => EmployeeTicket::factory(),
            'company_id' => fn (array $attributes): string => EmployeeTicket::query()->findOrFail($attributes['employee_ticket_id'])->company_id,
            'actor_id' => User::factory(), 'action' => 'CREATED', 'from_status' => null, 'to_status' => 'OPEN', 'created_at' => now(),
        ];
    }
}

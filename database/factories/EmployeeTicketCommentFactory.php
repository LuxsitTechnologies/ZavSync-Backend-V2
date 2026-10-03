<?php

namespace Database\Factories;

use App\Models\EmployeeTicket;
use App\Models\EmployeeTicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTicketComment>
 */
class EmployeeTicketCommentFactory extends Factory
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
            'author_id' => User::factory(), 'body' => fake()->sentence(), 'request_key_hash' => hash('sha256', fake()->uuid()),
            'payload_hash' => hash('sha256', fake()->uuid()), 'created_at' => now(),
        ];
    }
}

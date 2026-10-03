<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeTicket>
 */
class EmployeeTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes): string => Employee::factory()->for(Company::query()->findOrFail($attributes['company_id']))->create()->id,
            'created_by' => User::factory(), 'subject' => fake()->sentence(4), 'description' => fake()->sentence(),
            'category' => null, 'priority' => 'NORMAL', 'status' => 'OPEN', 'version' => 1,
            'request_key_hash' => hash('sha256', fake()->uuid()), 'payload_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\EmployeeExpenseClaim;
use App\Models\EmployeeExpenseClaimEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeExpenseClaimEvent>
 */
class EmployeeExpenseClaimEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['employee_expense_claim_id' => EmployeeExpenseClaim::factory(),
            'company_id' => fn (array $attributes): string => EmployeeExpenseClaim::query()->findOrFail($attributes['employee_expense_claim_id'])->company_id,
            'event_type' => 'CREATED', 'claim_version' => 1, 'actor_id' => User::factory(), 'occurred_at' => now()];
    }
}

<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeAssetRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeAssetRequest>
 */
class EmployeeAssetRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['company_id' => Company::factory(),
            'employee_id' => fn (array $attributes): string => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'type' => 'NEW_EQUIPMENT', 'item_description' => fake()->sentence(), 'reason' => fake()->sentence(),
            'status' => 'PENDING', 'version' => 1, 'created_by' => User::factory()];
    }
}

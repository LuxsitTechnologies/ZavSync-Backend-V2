<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'code' => strtoupper(fake()->unique()->bothify('WH-###')),
            'name' => fake()->company().' Warehouse', 'location' => fake()->address(),
            'is_active' => true, 'is_default' => false, 'created_by' => User::factory(),
        ];
    }

    public function default(): static
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}

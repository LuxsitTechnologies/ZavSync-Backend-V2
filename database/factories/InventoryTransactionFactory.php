<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InventoryTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'sequence' => fake()->unique()->numberBetween(1, 100000),
            'number' => fake()->unique()->bothify('INV-2026-#####'), 'type' => 'positive_adjustment',
            'transaction_date' => '2026-09-15', 'reference' => fake()->bothify('REF-####'),
            'idempotency_key' => fake()->uuid(), 'idempotency_hash' => hash('sha256', fake()->uuid()),
            'created_by' => User::factory(),
        ];
    }
}

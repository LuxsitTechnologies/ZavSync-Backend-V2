<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(), 'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'name' => fake()->words(3, true), 'type' => 'inventory', 'track_inventory' => true,
            'unit' => 'unit', 'is_active' => true, 'sales_price' => 150000,
            'default_purchase_cost' => 100000, 'reorder_level_milli' => 5000,
            'reorder_quantity_milli' => 10000, 'created_by' => User::factory(),
        ];
    }

    public function service(): static
    {
        return $this->state(fn (): array => ['type' => 'service', 'track_inventory' => false]);
    }
}

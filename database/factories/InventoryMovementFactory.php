<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\InventoryTransaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => InventoryTransaction::query()->find($attributes['inventory_transaction_id'])?->company_id,
            'inventory_transaction_id' => InventoryTransaction::factory(), 'item_id' => InventoryItem::factory(),
            'warehouse_id' => Warehouse::factory(), 'type' => 'positive_adjustment', 'movement_date' => '2026-09-15',
            'quantity_in_milli' => 1000, 'quantity_out_milli' => 0, 'unit_cost' => 10000,
            'movement_value' => 10000, 'value_delta' => 10000, 'created_by' => User::factory(),
        ];
    }
}

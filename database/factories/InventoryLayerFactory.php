<?php

namespace Database\Factories;

use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryLayer>
 */
class InventoryLayerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => InventoryMovement::query()->find($attributes['source_movement_id'])?->company_id,
            'item_id' => fn (array $attributes) => InventoryMovement::query()->find($attributes['source_movement_id'])?->item_id,
            'warehouse_id' => fn (array $attributes) => InventoryMovement::query()->find($attributes['source_movement_id'])?->warehouse_id,
            'source_movement_id' => InventoryMovement::factory(), 'original_quantity_milli' => 1000,
            'remaining_quantity_milli' => 1000, 'unit_cost' => 10000, 'original_value' => 10000,
            'remaining_value' => 10000, 'received_date' => '2026-09-15',
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\InventoryConsumption;
use App\Models\InventoryLayer;
use App\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryConsumption>
 */
class InventoryConsumptionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => fn (array $attributes) => InventoryMovement::query()->find($attributes['outbound_movement_id'])?->company_id,
            'outbound_movement_id' => InventoryMovement::factory(['type' => 'sale_issue', 'quantity_in_milli' => 0, 'quantity_out_milli' => 1000, 'value_delta' => -10000]),
            'inventory_layer_id' => InventoryLayer::factory(), 'quantity_milli' => 1000,
            'unit_cost' => 10000, 'value' => 10000,
        ];
    }
}

<?php

namespace App\Models;

use Database\Factories\InventoryConsumptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryConsumption extends Model
{
    /** @use HasFactory<InventoryConsumptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'outbound_movement_id', 'inventory_layer_id', 'quantity_milli', 'unit_cost', 'value'];

    protected function casts(): array
    {
        return ['quantity_milli' => 'integer', 'unit_cost' => 'integer', 'value' => 'integer'];
    }

    public function outboundMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'outbound_movement_id');
    }

    public function layer(): BelongsTo
    {
        return $this->belongsTo(InventoryLayer::class, 'inventory_layer_id');
    }
}

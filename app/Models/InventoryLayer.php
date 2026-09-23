<?php

namespace App\Models;

use Database\Factories\InventoryLayerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryLayer extends Model
{
    /** @use HasFactory<InventoryLayerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'item_id', 'warehouse_id', 'source_movement_id', 'original_quantity_milli', 'remaining_quantity_milli', 'unit_cost', 'original_value', 'remaining_value', 'received_date'];

    protected function casts(): array
    {
        return ['original_quantity_milli' => 'integer', 'remaining_quantity_milli' => 'integer', 'unit_cost' => 'integer', 'original_value' => 'integer', 'remaining_value' => 'integer', 'received_date' => 'date:Y-m-d'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'source_movement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryConsumption::class);
    }
}

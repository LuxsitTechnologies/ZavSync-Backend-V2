<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Database\Factories\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'inventory_transaction_id', 'item_id', 'warehouse_id', 'type', 'movement_date', 'quantity_in_milli', 'quantity_out_milli', 'unit_cost', 'movement_value', 'value_delta', 'source_type', 'source_id', 'source_line_id', 'original_movement_id', 'created_by'];

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'movement_date' => 'date:Y-m-d', 'quantity_in_milli' => 'integer', 'quantity_out_milli' => 'integer', 'unit_cost' => 'integer', 'movement_value' => 'integer', 'value_delta' => 'integer'];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'item_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_movement_id');
    }

    public function layers(): HasMany
    {
        return $this->hasMany(InventoryLayer::class, 'source_movement_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(InventoryConsumption::class, 'outbound_movement_id');
    }
}

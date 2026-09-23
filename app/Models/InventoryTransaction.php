<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Database\Factories\InventoryTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryTransaction extends Model
{
    /** @use HasFactory<InventoryTransactionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence', 'number', 'type', 'transaction_date', 'source_warehouse_id', 'destination_warehouse_id', 'source_type', 'source_id', 'reference', 'reason', 'notes', 'journal_id', 'idempotency_key', 'idempotency_hash', 'created_by'];

    protected function casts(): array
    {
        return ['type' => InventoryMovementType::class, 'transaction_date' => 'date:Y-m-d', 'sequence' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}

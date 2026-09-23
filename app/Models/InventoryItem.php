<?php

namespace App\Models;

use App\Enums\InventoryItemType;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sku', 'name', 'description', 'type', 'track_inventory', 'unit', 'sales_unit', 'purchase_unit', 'category', 'barcode', 'is_active', 'sales_price', 'default_purchase_cost', 'reorder_level_milli', 'reorder_quantity_milli', 'inventory_asset_account_id', 'cogs_account_id', 'sales_account_id', 'inventory_adjustment_account_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['type' => InventoryItemType::class, 'track_inventory' => 'boolean', 'is_active' => 'boolean', 'sales_price' => 'integer', 'default_purchase_cost' => 'integer', 'reorder_level_milli' => 'integer', 'reorder_quantity_milli' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inventoryAssetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_asset_account_id');
    }

    public function cogsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cogs_account_id');
    }

    public function salesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_account_id');
    }

    public function inventoryAdjustmentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'inventory_adjustment_account_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'item_id');
    }

    public function layers(): HasMany
    {
        return $this->hasMany(InventoryLayer::class, 'item_id');
    }

    public function isTracked(): bool
    {
        return $this->type === InventoryItemType::Inventory && $this->track_inventory;
    }
}

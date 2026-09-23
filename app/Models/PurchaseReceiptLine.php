<?php

namespace App\Models;

use Database\Factories\PurchaseReceiptLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceiptLine extends Model
{
    /** @use HasFactory<PurchaseReceiptLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['purchase_receipt_id', 'purchase_order_line_id', 'ordered_quantity_milli', 'previously_received_quantity_milli', 'quantity_received_milli', 'remaining_quantity_milli'];

    protected function casts(): array
    {
        return ['ordered_quantity_milli' => 'integer', 'previously_received_quantity_milli' => 'integer', 'quantity_received_milli' => 'integer', 'remaining_quantity_milli' => 'integer'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function billLines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }
}

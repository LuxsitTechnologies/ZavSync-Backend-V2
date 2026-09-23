<?php

namespace App\Models;

use App\Enums\PurchaseReceiptStatus;
use Database\Factories\PurchaseReceiptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReceipt extends Model
{
    /** @use HasFactory<PurchaseReceiptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'purchase_order_id', 'supplier_id', 'sequence', 'number', 'receipt_date', 'status', 'notes', 'idempotency_key', 'idempotency_hash', 'received_by'];

    protected function casts(): array
    {
        return ['receipt_date' => 'date:Y-m-d', 'status' => PurchaseReceiptStatus::class];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }
}

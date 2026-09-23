<?php

namespace App\Models;

use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    /** @use HasFactory<PurchaseOrderLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['purchase_order_id', 'position', 'item_id', 'item_name', 'description', 'procurement_type', 'quantity_milli', 'unit', 'unit_price', 'subtotal', 'discount', 'taxable_amount', 'tax_rate_bps', 'tax_amount', 'total', 'expense_account_id', 'received_quantity_milli', 'billed_quantity_milli', 'metadata'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer', 'unit_price' => 'integer', 'subtotal' => 'integer', 'discount' => 'integer',
            'taxable_amount' => 'integer', 'tax_rate_bps' => 'integer', 'tax_amount' => 'integer', 'total' => 'integer',
            'received_quantity_milli' => 'integer', 'billed_quantity_milli' => 'integer', 'metadata' => 'array',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function receiptLines(): HasMany
    {
        return $this->hasMany(PurchaseReceiptLine::class);
    }

    public function billLines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }
}

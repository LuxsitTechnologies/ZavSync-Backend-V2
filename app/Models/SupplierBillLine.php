<?php

namespace App\Models;

use Database\Factories\SupplierBillLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillLine extends Model
{
    /** @use HasFactory<SupplierBillLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['supplier_bill_id', 'purchase_order_line_id', 'purchase_receipt_line_id', 'position', 'item_id', 'item_name', 'description', 'procurement_type', 'quantity_milli', 'unit', 'unit_price', 'subtotal', 'discount', 'taxable_amount', 'tax_rate_bps', 'tax_amount', 'withholding_rate_bps', 'withholding_amount', 'total', 'expense_account_id', 'metadata'];

    protected function casts(): array
    {
        return [
            'quantity_milli' => 'integer', 'unit_price' => 'integer', 'subtotal' => 'integer', 'discount' => 'integer',
            'taxable_amount' => 'integer', 'tax_rate_bps' => 'integer', 'tax_amount' => 'integer',
            'withholding_rate_bps' => 'integer', 'withholding_amount' => 'integer', 'total' => 'integer', 'metadata' => 'array',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function purchaseReceiptLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceiptLine::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}

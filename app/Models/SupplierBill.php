<?php

namespace App\Models;

use App\Enums\SupplierBillStatus;
use Carbon\CarbonInterface;
use Database\Factories\SupplierBillFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierBill extends Model
{
    /** @use HasFactory<SupplierBillFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'supplier_id', 'purchase_order_id', 'purchase_receipt_id', 'sequence', 'bill_number', 'supplier_invoice_number', 'bill_date', 'posting_date', 'due_date', 'currency', 'status', 'subtotal', 'discount', 'taxable_amount', 'purchase_tax', 'withholding_tax', 'gross_total', 'total', 'amount_paid', 'balance_due', 'notes', 'creation_idempotency_key', 'creation_idempotency_hash', 'journal_id', 'reversal_journal_id', 'created_by', 'updated_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at'];

    protected function casts(): array
    {
        return [
            'bill_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'status' => SupplierBillStatus::class,
            'subtotal' => 'integer', 'discount' => 'integer', 'taxable_amount' => 'integer', 'purchase_tax' => 'integer',
            'withholding_tax' => 'integer', 'gross_total' => 'integer', 'total' => 'integer', 'amount_paid' => 'integer',
            'balance_due' => 'integer', 'posted_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class)->orderBy('position');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function displayStatus(?CarbonInterface $asOf = null): string
    {
        if ($this->status->acceptsPayments() && $this->balance_due > 0 && $this->due_date->isBefore(($asOf ?? now())->startOfDay())) {
            return 'overdue';
        }

        return $this->status->value;
    }
}

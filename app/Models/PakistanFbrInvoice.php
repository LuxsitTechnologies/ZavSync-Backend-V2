<?php

namespace App\Models;

use App\Enums\FbrSubmissionStatus;
use Database\Factories\PakistanFbrInvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Pakistan tax document. This model has no accounting posting or payment relationships. */
class PakistanFbrInvoice extends Model
{
    /** @use HasFactory<PakistanFbrInvoiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'customer_id', 'is_historical', 'document_state', 'legacy_import_run_id', 'legacy_source_system', 'legacy_source_id', 'legacy_original_company_id', 'legacy_status', 'historical_accounting_state', 'migration_reconciliation_state', 'buyer_snapshot', 'legacy_original_timestamps', 'legacy_original_financial_values', 'sequence', 'invoice_number', 'invoice_date', 'due_date', 'invoice_type', 'sale_type', 'origin_province', 'destination_province', 'currency', 'subtotal', 'discount', 'taxable_amount', 'sales_tax', 'other_tax', 'advance_tax', 'withholding_tax', 'total', 'amount_paid', 'notes', 'fbr_status', 'fbr_reference_number', 'fbr_response_metadata', 'creation_idempotency_key', 'creation_idempotency_hash', 'created_by', 'updated_by'];

    protected $hidden = ['creation_idempotency_key', 'creation_idempotency_hash'];

    protected $attributes = ['fbr_status' => 'not_submitted', 'is_historical' => false, 'document_state' => 'DRAFT', 'amount_paid' => 0];

    protected function casts(): array
    {
        return [
            'is_historical' => 'boolean', 'buyer_snapshot' => 'array', 'legacy_original_timestamps' => 'array',
            'legacy_original_financial_values' => 'array', 'invoice_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d',
            'fbr_status' => FbrSubmissionStatus::class, 'fbr_response_metadata' => 'array', 'sequence' => 'integer',
            'subtotal' => 'integer', 'discount' => 'integer', 'taxable_amount' => 'integer', 'sales_tax' => 'integer',
            'other_tax' => 'integer', 'advance_tax' => 'integer', 'withholding_tax' => 'integer', 'total' => 'integer', 'amount_paid' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            if ($invoice->getOriginal('is_historical') && array_diff(array_keys($invoice->getDirty()), ['migration_reconciliation_state', 'updated_at']) !== []) {
                throw new LogicException('Historical Pakistan/FBR documents are immutable evidence.');
            }
        });
        static::deleting(function (self $invoice): void {
            if ($invoice->is_historical || $invoice->fbr_status !== FbrSubmissionStatus::NotSubmitted) {
                throw new LogicException('Historical or submitted Pakistan/FBR documents cannot be deleted.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PakistanFbrInvoiceLine::class, 'invoice_id')->orderBy('position');
    }

    public function fbrAttempts(): HasMany
    {
        return $this->hasMany(PakistanFbrSubmissionAttempt::class, 'invoice_id');
    }

    public function legacyFbrEvidence(): HasMany
    {
        return $this->hasMany(LegacyFbrEvidence::class, 'invoice_id');
    }

    public function legacyImportRun(): BelongsTo
    {
        return $this->belongsTo(LegacyImportRun::class, 'legacy_import_run_id');
    }

    public function isEditable(): bool
    {
        return ! $this->is_historical && $this->document_state === 'DRAFT'
            && $this->fbr_reference_number === null
            && in_array($this->fbr_status, [FbrSubmissionStatus::NotSubmitted, FbrSubmissionStatus::Rejected], true);
    }
}

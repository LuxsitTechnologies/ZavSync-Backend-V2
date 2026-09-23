<?php

namespace App\Models;

use App\Enums\FbrSubmissionStatus;
use App\Enums\InvoiceStatus;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'customer_id', 'sequence', 'invoice_number', 'invoice_date', 'due_date', 'currency', 'status', 'subtotal', 'discount', 'taxable_amount', 'sales_tax', 'other_tax', 'advance_tax', 'withholding_tax', 'total', 'amount_paid', 'balance_due', 'notes', 'terms', 'fbr_status', 'fbr_reference_number', 'fbr_response_metadata', 'creation_idempotency_key', 'creation_idempotency_hash', 'journal_id', 'reversal_journal_id', 'created_by', 'updated_by', 'posted_by', 'posted_at', 'voided_by', 'voided_at'];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'status' => InvoiceStatus::class,
            'fbr_status' => FbrSubmissionStatus::class, 'fbr_response_metadata' => 'array',
            'subtotal' => 'integer', 'discount' => 'integer', 'taxable_amount' => 'integer', 'sales_tax' => 'integer',
            'other_tax' => 'integer', 'advance_tax' => 'integer', 'withholding_tax' => 'integer', 'total' => 'integer',
            'amount_paid' => 'integer', 'balance_due' => 'integer', 'posted_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }

    public function fbrAttempts(): HasMany
    {
        return $this->hasMany(FbrSubmissionAttempt::class);
    }

    public function displayStatus(?CarbonInterface $asOf = null): string
    {
        if ($this->status->acceptsPayments() && $this->balance_due > 0 && $this->due_date->isBefore(($asOf ?? now())->startOfDay())) {
            return 'overdue';
        }

        return $this->status->value;
    }
}

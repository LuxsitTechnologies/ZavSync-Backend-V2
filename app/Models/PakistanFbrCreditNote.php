<?php

namespace App\Models;

use Database\Factories\PakistanFbrCreditNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PakistanFbrCreditNote extends Model
{
    /** @use HasFactory<PakistanFbrCreditNoteFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'invoice_id', 'sequence', 'credit_note_number', 'idempotency_key', 'payload_hash', 'state', 'issued_on', 'reason', 'original_invoice_number', 'original_fbr_reference_number', 'original_fbr_evidence', 'seller_snapshot', 'buyer_snapshot', 'portal_snapshot', 'fbr_reference_number', 'fbr_response_metadata', 'submitted_at', 'accepted_at', 'taxable_amount', 'sales_tax', 'extra_tax', 'further_tax', 'st_withheld', 'total', 'created_by'];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'issued_on' => 'date:Y-m-d', 'original_fbr_evidence' => 'array', 'seller_snapshot' => 'array', 'buyer_snapshot' => 'array', 'portal_snapshot' => 'array', 'fbr_response_metadata' => 'array', 'submitted_at' => 'datetime', 'accepted_at' => 'datetime', 'taxable_amount' => 'integer', 'sales_tax' => 'integer', 'extra_tax' => 'integer', 'further_tax' => 'integer', 'st_withheld' => 'integer', 'total' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $credit): void {
            $oldState = $credit->getOriginal('state');
            $nextState = $credit->state;
            $transitions = [
                'DRAFT' => ['DRAFT', 'IN_PROGRESS'],
                'IN_PROGRESS' => ['IN_PROGRESS', 'OUTCOME_UNCERTAIN', 'REJECTED', 'ACCEPTED'],
                'OUTCOME_UNCERTAIN' => ['OUTCOME_UNCERTAIN', 'REJECTED', 'ACCEPTED'],
                'REJECTED' => ['REJECTED', 'IN_PROGRESS'],
            ];
            $allowedFields = ['state', 'reason', 'submitted_at', 'accepted_at', 'fbr_reference_number', 'fbr_response_metadata', 'updated_at'];
            if (! in_array($nextState, $transitions[$oldState] ?? [], true)
                || array_diff(array_keys($credit->getDirty()), $allowedFields) !== []
                || ($credit->isDirty('reason') && ! in_array($oldState, ['DRAFT', 'REJECTED'], true))) {
                throw new LogicException('FBR credit-note financial snapshots and accepted documents are immutable.');
            }
        });
        static::deleting(function (self $credit): void {
            if ($credit->state !== 'DRAFT' || $credit->attempts()->exists()) {
                throw new LogicException('Only an unsubmitted FBR credit-note draft may be deleted.');
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(PakistanFbrInvoice::class, 'invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PakistanFbrCreditNoteLine::class, 'credit_note_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PakistanFbrCreditNoteAttempt::class, 'credit_note_id');
    }
}

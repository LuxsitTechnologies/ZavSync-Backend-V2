<?php

namespace App\Models;

use Database\Factories\PakistanFbrCreditNoteLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PakistanFbrCreditNoteLine extends Model
{
    /** @use HasFactory<PakistanFbrCreditNoteLineFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['credit_note_id', 'invoice_line_id', 'source_snapshot', 'taxable_amount', 'sales_tax', 'extra_tax', 'further_tax', 'st_withheld', 'total'];

    protected function casts(): array
    {
        return ['source_snapshot' => 'array', 'taxable_amount' => 'integer', 'sales_tax' => 'integer', 'extra_tax' => 'integer', 'further_tax' => 'integer', 'st_withheld' => 'integer', 'total' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Recorded internal FBR credit note lines are immutable.');
        });
        static::deleting(function (self $line): void {
            if ($line->creditNote->state !== 'DRAFT' || $line->creditNote->attempts()->exists()) {
                throw new LogicException('Submitted FBR credit-note lines must be retained.');
            }
        });
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(PakistanFbrCreditNote::class, 'credit_note_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(PakistanFbrInvoiceLine::class, 'invoice_line_id');
    }
}

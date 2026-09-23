<?php

namespace App\Models;

use Database\Factories\AccountingCloseRecordFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingCloseRecord extends Model
{
    /** @use HasFactory<AccountingCloseRecordFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'close_type', 'accounting_period_id', 'fiscal_year_id', 'status', 'checklist_snapshot', 'reason', 'closing_journal_id', 'reversal_journal_id', 'idempotency_key', 'idempotency_hash', 'closed_by', 'closed_at', 'reopened_by', 'reopened_at'];

    protected function casts(): array
    {
        return ['checklist_snapshot' => 'array', 'closed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function closingJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'closing_journal_id');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'reversal_journal_id');
    }
}

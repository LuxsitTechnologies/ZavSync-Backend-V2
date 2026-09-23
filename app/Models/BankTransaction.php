<?php

namespace App\Models;

use App\Enums\BankTransactionDirection;
use App\Enums\BankTransactionStatus;
use Database\Factories\BankTransactionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    /** @use HasFactory<BankTransactionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'financial_account_id', 'bank_statement_import_id', 'statement_row', 'evidence_type', 'transaction_date', 'value_date', 'description', 'bank_reference', 'external_transaction_id', 'direction', 'amount', 'running_balance', 'currency', 'counterparty_name', 'counterparty_account', 'fingerprint', 'status', 'classification_journal_id', 'origin_idempotency_key', 'origin_idempotency_hash', 'created_by'];

    protected function casts(): array
    {
        return ['transaction_date' => 'date:Y-m-d', 'value_date' => 'date:Y-m-d', 'direction' => BankTransactionDirection::class, 'status' => BankTransactionStatus::class, 'amount' => 'integer', 'running_balance' => 'integer', 'statement_row' => 'integer'];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function classificationJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'classification_journal_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }
}

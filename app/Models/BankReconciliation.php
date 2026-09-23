<?php

namespace App\Models;

use Database\Factories\BankReconciliationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    /** @use HasFactory<BankReconciliationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence', 'number', 'financial_account_id', 'bank_statement_import_id', 'period_start', 'period_end', 'statement_opening_balance', 'statement_closing_balance', 'book_balance', 'difference', 'status', 'idempotency_key', 'idempotency_hash', 'created_by', 'completed_by', 'completed_at', 'reopened_by', 'reopened_at', 'reopen_reason'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'statement_opening_balance' => 'integer', 'statement_closing_balance' => 'integer', 'book_balance' => 'integer', 'difference' => 'integer', 'sequence' => 'integer', 'completed_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function statementImport(): BelongsTo
    {
        return $this->belongsTo(BankStatementImport::class, 'bank_statement_import_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }
}

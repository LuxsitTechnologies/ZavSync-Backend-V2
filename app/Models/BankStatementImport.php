<?php

namespace App\Models;

use Database\Factories\BankStatementImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementImport extends Model
{
    /** @use HasFactory<BankStatementImportFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'financial_account_id', 'original_filename', 'file_hash', 'statement_reference', 'statement_start_date', 'statement_end_date', 'opening_balance', 'closing_balance', 'status', 'column_mapping', 'preview_rows', 'row_count', 'imported_count', 'duplicate_count', 'idempotency_key', 'idempotency_hash', 'imported_by', 'confirmed_at'];

    protected function casts(): array
    {
        return ['statement_start_date' => 'date:Y-m-d', 'statement_end_date' => 'date:Y-m-d', 'opening_balance' => 'integer', 'closing_balance' => 'integer', 'column_mapping' => 'array', 'preview_rows' => 'array', 'row_count' => 'integer', 'imported_count' => 'integer', 'duplicate_count' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}

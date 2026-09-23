<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'name', 'type', 'bank_name', 'account_title', 'masked_account_number', 'iban', 'currency', 'gl_account_id', 'opening_balance', 'is_default', 'is_active', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['type' => FinancialAccountType::class, 'opening_balance' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id');
    }

    public function statementImports(): HasMany
    {
        return $this->hasMany(BankStatementImport::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}

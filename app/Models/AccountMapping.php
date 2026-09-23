<?php

namespace App\Models;

use Database\Factories\AccountMappingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    /** @use HasFactory<AccountMappingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'key', 'account_id', 'updated_by'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}

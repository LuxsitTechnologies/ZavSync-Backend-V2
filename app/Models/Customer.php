<?php

namespace App\Models;

use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'sequence', 'code', 'name', 'legal_name', 'type', 'ntn', 'cnic', 'strn', 'email', 'phone', 'billing_address', 'city', 'province', 'country', 'postal_code', 'contact_person', 'payment_terms_days', 'credit_limit', 'currency', 'tax_metadata', 'is_active', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'payment_terms_days' => 'integer', 'credit_limit' => 'integer', 'tax_metadata' => 'array', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CustomerPayment::class);
    }
}

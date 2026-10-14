<?php

namespace App\Models;

use Database\Factories\BillingCheckoutAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCheckoutAttempt extends Model
{
    /** @use HasFactory<BillingCheckoutAttemptFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'idempotency_key', 'payload_hash', 'operation', 'status', 'claim_generation', 'claimed_at', 'currency',
        'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor', 'price_snapshot', 'provider_session_id',
        'provider_subscription_id', 'provider_customer_id', 'completed_at', 'requested_by'];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['claim_generation' => 'integer', 'claimed_at' => 'datetime',
            'subtotal_minor' => 'integer', 'discount_minor' => 'integer', 'tax_minor' => 'integer',
            'total_minor' => 'integer', 'price_snapshot' => 'array', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

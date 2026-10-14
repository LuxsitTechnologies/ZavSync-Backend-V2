<?php

namespace App\Models;

use Database\Factories\BillingPlanChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingPlanChange extends Model
{
    /** @use HasFactory<BillingPlanChangeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'subscription_id', 'source_catalogue_price_id', 'target_catalogue_price_id',
        'idempotency_key', 'payload_hash', 'operation', 'status', 'claim_generation', 'claimed_at', 'source_plan_code',
        'target_plan_code', 'target_billing_interval', 'currency', 'target_amount_minor',
        'previous_paid_through_at', 'effective_at', 'provider_invoice_id', 'provider_schedule_id',
        'completed_at', 'requested_by'];

    protected $hidden = ['idempotency_key', 'payload_hash'];

    protected function casts(): array
    {
        return ['claim_generation' => 'integer', 'target_amount_minor' => 'integer',
            'claimed_at' => 'datetime', 'previous_paid_through_at' => 'datetime',
            'effective_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}

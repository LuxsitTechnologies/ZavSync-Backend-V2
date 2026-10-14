<?php

namespace App\Models;

use Database\Factories\BillingComponentSubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingComponentSubscription extends Model
{
    /** @use HasFactory<BillingComponentSubscriptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'subscription_id', 'catalogue_price_id', 'checkout_attempt_id',
        'kind', 'code', 'currency', 'billing_interval', 'quantity', 'status', 'provider_subscription_id',
        'paid_through_at', 'cancel_at_period_end_at', 'scheduled_quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'scheduled_quantity' => 'integer', 'paid_through_at' => 'datetime',
            'cancel_at_period_end_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function cataloguePrice(): BelongsTo
    {
        return $this->belongsTo(BillingCataloguePrice::class, 'catalogue_price_id');
    }
}

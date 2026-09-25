<?php

namespace App\Models;

use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'plan_id', 'status', 'billing_interval', 'starts_at', 'trial_ends_at', 'renews_at', 'ends_at', 'cancelled_at', 'provider', 'provider_reference'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'trial_ends_at' => 'datetime', 'renews_at' => 'datetime', 'ends_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}

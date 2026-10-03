<?php

namespace App\Models;

use Database\Factories\LeaveEntitlementAdjustmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveEntitlementAdjustment extends Model
{
    /** @use HasFactory<LeaveEntitlementAdjustmentFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = ['company_id', 'leave_entitlement_id', 'delta_units', 'reason', 'request_key_hash', 'payload_hash', 'created_by', 'created_at'];

    protected $hidden = ['request_key_hash', 'payload_hash'];

    protected function casts(): array
    {
        return ['delta_units' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(LeaveEntitlement::class, 'leave_entitlement_id');
    }
}

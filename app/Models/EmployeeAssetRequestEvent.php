<?php

namespace App\Models;

use Database\Factories\EmployeeAssetRequestEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAssetRequestEvent extends Model
{
    /** @use HasFactory<EmployeeAssetRequestEventFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'employee_asset_request_id', 'event_type', 'request_version', 'reason', 'actor_id', 'occurred_at'];

    protected function casts(): array
    {
        return ['request_version' => 'integer', 'occurred_at' => 'datetime'];
    }

    public function assetRequest(): BelongsTo
    {
        return $this->belongsTo(EmployeeAssetRequest::class, 'employee_asset_request_id');
    }
}

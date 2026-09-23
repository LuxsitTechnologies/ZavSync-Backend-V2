<?php

namespace App\Models;

use Database\Factories\GatewaySettlementAllocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewaySettlementAllocation extends Model
{
    /** @use HasFactory<GatewaySettlementAllocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['company_id', 'gateway_settlement_id', 'source_type', 'source_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(GatewaySettlement::class, 'gateway_settlement_id');
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GatewaySettlementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'provider' => $this->provider, 'settlement_reference' => $this->settlement_reference, 'settlement_date' => $this->settlement_date?->toDateString(), 'gross_amount' => $this->gross_amount, 'fee_amount' => $this->fee_amount, 'adjustment_amount' => $this->adjustment_amount, 'net_amount' => $this->net_amount, 'currency' => $this->currency, 'destination_financial_account_id' => $this->destination_financial_account_id, 'destination_account' => $this->whenLoaded('destinationAccount'), 'clearing_account_id' => $this->clearing_account_id, 'fee_account_id' => $this->fee_account_id, 'status' => $this->status, 'journal_id' => $this->journal_id, 'allocations' => $this->whenLoaded('allocations'), 'posted_at' => $this->posted_at, 'created_at' => $this->created_at];
    }
}

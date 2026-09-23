<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FiscalYearResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'name' => $this->name, 'start_date' => $this->start_date->format('Y-m-d'), 'end_date' => $this->end_date->format('Y-m-d'), 'currency' => $this->currency, 'status' => $this->status, 'closed_by' => $this->closed_by, 'closed_at' => $this->closed_at?->toISOString(), 'reopened_by' => $this->reopened_by, 'reopened_at' => $this->reopened_at?->toISOString(), 'reopen_reason' => $this->reopen_reason, 'periods' => AccountingPeriodResource::collection($this->whenLoaded('periods'))];
    }
}

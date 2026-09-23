<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountingPeriodResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'fiscal_year_id' => $this->fiscal_year_id ? (string) $this->fiscal_year_id : null, 'name' => $this->name, 'start_date' => $this->start_date->format('Y-m-d'), 'end_date' => $this->end_date->format('Y-m-d'), 'status' => $this->status, 'closed_by' => $this->closed_by ? (string) $this->closed_by : null, 'closed_at' => $this->closed_at?->toISOString()];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayrollPeriodResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'fiscal_year_id' => $this->fiscal_year_id, 'accounting_period_id' => $this->accounting_period_id, 'name' => $this->name, 'frequency' => $this->frequency, 'period_start' => $this->period_start?->format('Y-m-d'), 'period_end' => $this->period_end?->format('Y-m-d'), 'pay_date' => $this->pay_date?->format('Y-m-d'), 'status' => $this->status];
    }
}

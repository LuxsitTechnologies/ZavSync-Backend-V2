<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BudgetResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => (string) $this->id, 'company_id' => (string) $this->company_id, 'fiscal_year_id' => (string) $this->fiscal_year_id, 'name' => $this->name, 'version' => $this->version, 'status' => $this->status, 'currency' => $this->currency, 'description' => $this->description, 'is_active' => $this->is_active, 'based_on_budget_id' => $this->based_on_budget_id, 'submitted_at' => $this->submitted_at?->toISOString(), 'approved_at' => $this->approved_at?->toISOString(), 'activated_at' => $this->activated_at?->toISOString(), 'fiscal_year' => new FiscalYearResource($this->whenLoaded('fiscalYear')), 'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line): array => ['id' => (string) $line->id, 'account_id' => (string) $line->account_id, 'accounting_period_id' => (string) $line->accounting_period_id, 'amount' => $line->amount, 'account' => $line->relationLoaded('account') ? ['code' => $line->account->code, 'name' => $line->account->name, 'type' => $line->account->type, 'subtype' => $line->account->subtype] : null, 'period' => $line->relationLoaded('period') ? ['name' => $line->period->name, 'start_date' => $line->period->start_date->format('Y-m-d'), 'end_date' => $line->period->end_date->format('Y-m-d')] : null]))];
    }
}

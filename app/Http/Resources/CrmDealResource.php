<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmDealResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'companyId' => (string) $this->company_id, 'name' => $this->title,
            'accountId' => $this->account_id, 'company' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'primaryContactId' => $this->primary_contact_id, 'contact' => $this->whenLoaded('primaryContact', fn (): ?string => $this->primaryContact ? trim($this->primaryContact->first_name.' '.$this->primaryContact->last_name) : null),
            'leadOriginId' => $this->lead_origin_id, 'pipelineId' => $this->pipeline_id, 'pipelineStageId' => $this->pipeline_stage_id,
            'pipeline' => $this->whenLoaded('pipeline', fn (): ?string => $this->pipeline?->name), 'stage' => $this->whenLoaded('stage', fn (): ?string => $this->stage?->name),
            'ownerId' => $this->owner_id, 'owner' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'customerId' => $this->customer_id, 'value' => $this->amount, 'currency' => $this->currency,
            'probabilityBasisPoints' => $this->probability_bps, 'probability' => intdiv((int) $this->probability_bps, 100),
            'weightedValue' => intdiv(((int) $this->amount * (int) $this->probability_bps) + 5000, 10000),
            'expectedClose' => $this->expected_close_date?->format('Y-m-d'), 'actualClose' => $this->actual_close_date?->format('Y-m-d'),
            'status' => $this->status, 'source' => $this->source ?? '', 'description' => $this->description,
            'lossReason' => $this->loss_reason, 'closedAt' => $this->closed_at?->toISOString(),
            'lastActivity' => $this->activities_max_created_at ? Carbon::parse($this->activities_max_created_at)->toISOString() : null,
            'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

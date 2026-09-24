<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmLeadResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'companyId' => (string) $this->company_id,
            'accountId' => $this->account_id, 'contactId' => $this->contact_id, 'ownerId' => $this->owner_id,
            'firstName' => $this->first_name, 'lastName' => $this->last_name ?? '', 'name' => trim($this->first_name.' '.$this->last_name),
            'company' => $this->company_name ?? $this->whenLoaded('account', fn (): string => (string) ($this->account?->name ?? '')),
            'title' => $this->job_title ?? '', 'email' => $this->email ?? '', 'phone' => $this->phone ?? '',
            'mobile' => $this->mobile, 'website' => $this->website ?? '', 'source' => $this->source ?? '',
            'status' => $this->status, 'stage' => $this->status, 'value' => $this->estimated_value, 'currency' => $this->currency,
            'expectedTimeframe' => $this->expected_timeframe?->format('Y-m-d'), 'interest' => $this->interest,
            'notes' => $this->notes ?? '', 'qualificationNotes' => $this->qualification_notes,
            'score' => ['fit' => (int) $this->score, 'intent' => 0, 'total' => (int) $this->score, 'heat' => $this->score >= 75 ? 'Hot' : ($this->score >= 50 ? 'Warm' : 'Cold')],
            'owner' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'convertedAt' => $this->converted_at?->toISOString(), 'convertedAccountId' => $this->converted_account_id,
            'convertedContactId' => $this->converted_contact_id, 'convertedDealId' => $this->converted_deal_id,
            'lastActivity' => $this->activities_max_created_at ? Carbon::parse($this->activities_max_created_at)->toISOString() : null,
            'scoreEvents' => $this->whenLoaded('scoreEvents', fn () => $this->scoreEvents->map(fn ($event): array => ['id' => $event->id, 'points' => $event->points, 'reason' => $event->reason, 'details' => $event->details, 'calculatedAt' => $event->calculated_at?->toISOString()])),
            'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

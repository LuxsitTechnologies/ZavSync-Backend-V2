<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmContactResource extends JsonResource
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
            'accountId' => $this->account_id, 'company' => $this->whenLoaded('account', fn (): ?string => $this->account?->name),
            'firstName' => $this->first_name, 'lastName' => $this->last_name ?? '', 'name' => trim($this->first_name.' '.$this->last_name),
            'title' => $this->job_title ?? '', 'department' => $this->department, 'email' => $this->email ?? '',
            'phone' => $this->phone ?? '', 'mobile' => $this->mobile, 'isPrimary' => $this->is_primary,
            'address' => $this->address, 'notes' => $this->notes, 'status' => $this->status,
            'ownerId' => $this->owner_id, 'owner' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'lastActivity' => $this->activities_max_created_at ? Carbon::parse($this->activities_max_created_at)->toISOString() : null,
            'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

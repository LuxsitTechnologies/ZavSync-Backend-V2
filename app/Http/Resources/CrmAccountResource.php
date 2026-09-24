<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmAccountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id, 'companyId' => (string) $this->company_id, 'name' => $this->name,
            'legalName' => $this->legal_name, 'email' => $this->email ?? '', 'phone' => $this->phone ?? '',
            'website' => $this->website ?? '', 'ntn' => $this->ntn, 'cnic' => $this->cnic,
            'registrationNumber' => $this->registration_number, 'industry' => $this->industry ?? '',
            'accountType' => $this->account_type, 'address' => $this->address ?? '', 'city' => $this->city,
            'country' => $this->country, 'postalCode' => $this->postal_code, 'source' => $this->source ?? '',
            'status' => $this->status, 'notes' => $this->notes, 'isArchived' => $this->is_archived,
            'ownerId' => $this->owner_id, 'owner' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'customerId' => $this->customer_id, 'primaryContact' => $this->whenLoaded('contacts', fn (): string => (string) ($this->contacts->firstWhere('is_primary', true)?->first_name ?? '')),
            'contactsCount' => (int) ($this->contacts_count ?? 0), 'dealsCount' => (int) ($this->deals_count ?? 0),
            'lastActivity' => $this->activities_max_created_at ? Carbon::parse($this->activities_max_created_at)->toISOString() : null,
            'createdAt' => $this->created_at?->toISOString(), 'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}

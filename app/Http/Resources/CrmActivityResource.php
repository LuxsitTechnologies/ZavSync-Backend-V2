<?php

namespace App\Http\Resources;

use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CrmActivityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $related = $this->whenLoaded('activityable', fn (): ?array => $this->activityable ? ['id' => (string) $this->activityable->getKey(), 'type' => class_basename($this->activityable), 'name' => $this->activityable->name ?? $this->activityable->title ?? trim(($this->activityable->first_name ?? '').' '.($this->activityable->last_name ?? ''))] : null);

        return [
            'id' => (string) $this->id, 'companyId' => (string) $this->company_id,
            'type' => ucfirst(strtolower($this->type)), 'subject' => $this->subject, 'title' => $this->subject,
            'description' => $this->description, 'detail' => $this->description ?? '', 'related' => $related,
            'accountId' => $this->activityable_type === CrmAccount::class ? $this->activityable_id : null,
            'contactId' => $this->activityable_type === CrmContact::class ? $this->activityable_id : null,
            'leadId' => $this->activityable_type === CrmLead::class ? $this->activityable_id : null,
            'dealId' => $this->activityable_type === CrmDeal::class ? $this->activityable_id : null,
            'ownerId' => $this->owner_id, 'owner' => $this->whenLoaded('owner', fn (): ?string => $this->owner?->name),
            'actor' => $this->whenLoaded('creator', fn (): ?string => $this->creator?->name),
            'dueAt' => $this->due_at?->toISOString(), 'completedAt' => $this->completed_at?->toISOString(),
            'status' => $this->status, 'priority' => ucfirst(strtolower($this->priority)), 'outcome' => $this->outcome,
            'timestamp' => $this->created_at?->toISOString(), 'isOverdue' => $this->status === 'PENDING' && $this->due_at?->isPast(),
        ];
    }
}

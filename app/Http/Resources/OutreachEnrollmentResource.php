<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutreachEnrollmentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'sequence_id' => $this->sequence_id, 'sequence_name' => $this->whenLoaded('sequence', fn () => $this->sequence->name), 'recipient_type' => $this->recipient_type, 'recipient_id' => $this->recipient_id, 'recipient_email' => $this->recipient_email, 'recipient_name' => $this->recipient_name, 'status' => $this->status, 'current_step_position' => $this->current_step_position, 'next_action_at' => $this->next_action_at?->toIso8601String(), 'enrolled_at' => $this->enrolled_at?->toIso8601String(), 'ended_at' => $this->ended_at?->toIso8601String(), 'messages_count' => $this->whenCounted('messages')];
    }
}

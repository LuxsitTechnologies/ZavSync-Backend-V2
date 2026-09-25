<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutreachMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'enrollment_id' => $this->enrollment_id, 'sequence_id' => $this->sequence_id, 'sequence_name' => $this->whenLoaded('sequence', fn () => $this->sequence->name), 'sequence_step_id' => $this->sequence_step_id, 'sending_identity_id' => $this->sending_identity_id, 'provider_connection_id' => $this->provider_connection_id, 'template_id' => $this->template_id, 'recipient_type' => $this->recipient_type, 'recipient_id' => $this->recipient_id, 'to_email' => $this->to_email, 'to_name' => $this->to_name, 'from_email' => $this->from_email, 'from_name' => $this->from_name, 'subject' => $this->subject, 'body_html' => $this->body_html, 'body_text' => $this->body_text, 'state' => $this->state, 'scheduled_at' => $this->scheduled_at?->toIso8601String(), 'sent_at' => $this->sent_at?->toIso8601String(), 'delivered_at' => $this->delivered_at?->toIso8601String(), 'bounced_at' => $this->bounced_at?->toIso8601String(), 'failed_at' => $this->failed_at?->toIso8601String(), 'replied_at' => $this->replied_at?->toIso8601String(), 'failure_message' => $this->failure_message, 'attempts_count' => $this->whenCounted('attempts'), 'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => ['id' => $event->id, 'type' => $event->type, 'occurred_at' => $event->occurred_at?->toIso8601String()]))];
    }
}

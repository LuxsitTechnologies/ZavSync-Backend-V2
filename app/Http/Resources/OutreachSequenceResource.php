<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutreachSequenceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'sending_identity_id' => $this->sending_identity_id, 'owner_id' => $this->owner_id, 'sending_identity' => EmailSendingIdentityResource::make($this->whenLoaded('sendingIdentity')), 'name' => $this->name, 'description' => $this->description, 'status' => $this->status, 'timezone' => $this->timezone, 'starts_at' => $this->starts_at?->toIso8601String(), 'allowed_weekdays' => $this->allowed_weekdays, 'send_window_start' => mb_substr($this->send_window_start, 0, 5), 'send_window_end' => mb_substr($this->send_window_end, 0, 5), 'track_opens' => $this->track_opens, 'track_clicks' => $this->track_clicks, 'stop_on_reply' => $this->stop_on_reply, 'steps' => $this->whenLoaded('steps', fn () => $this->steps->map(fn ($step) => ['id' => $step->id, 'position' => $step->position, 'type' => $step->type, 'template_id' => $step->template_id, 'subject' => $step->subject, 'body_html' => $step->body_html, 'body_text' => $step->body_text, 'wait_minutes' => $step->wait_minutes])), 'enrollments_count' => $this->whenCounted('enrollments'), 'messages_count' => $this->whenCounted('messages'), 'activated_at' => $this->activated_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String()];
    }
}

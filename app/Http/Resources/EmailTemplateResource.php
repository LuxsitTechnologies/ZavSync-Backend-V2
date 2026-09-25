<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailTemplateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'company_id' => $this->company_id, 'name' => $this->name, 'category' => $this->category, 'subject' => $this->subject, 'body_html' => $this->body_html, 'body_text' => $this->body_text, 'allowed_variables' => $this->allowed_variables ?? [], 'is_active' => $this->is_active, 'archived_at' => $this->archived_at?->toIso8601String(), 'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String()];
    }
}

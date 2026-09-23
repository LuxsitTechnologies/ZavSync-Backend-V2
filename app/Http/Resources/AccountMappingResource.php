<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountMappingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'company_id' => (string) $this->company_id, 'key' => $this->key, 'label' => $this->label,
            'description' => $this->description, 'required' => (bool) $this->required,
            'account_id' => $this->account_id ? (string) $this->account_id : null,
            'account' => $this->whenLoaded('account', fn () => ['id' => (string) $this->account->id, 'code' => $this->account->code, 'name' => $this->account->name, 'type' => $this->account->type]),
        ];
    }
}

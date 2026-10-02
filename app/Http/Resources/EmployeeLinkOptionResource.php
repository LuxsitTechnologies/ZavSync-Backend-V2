<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeLinkOptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $linked = (bool) $this->company_membership_exists;

        return [
            'id' => (string) $this->id,
            'employee_code' => $this->employee_code,
            'full_name' => $this->full_name,
            'status' => $this->status,
            'linked' => $linked,
            'available' => ! $linked,
        ];
    }
}

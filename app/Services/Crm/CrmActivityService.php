<?php

namespace App\Services\Crm;

use App\Models\CrmAccount;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\User;

class CrmActivityService
{
    /** @param array<string, mixed> $data */
    public function create(string $companyId, User $user, array $data): CrmActivity
    {
        $related = match ($data['related_type'] ?? null) {
            'account' => CrmAccount::class,
            'contact' => CrmContact::class,
            'lead' => CrmLead::class,
            'deal' => CrmDeal::class,
            default => null,
        };
        if ($related !== null) {
            $related::query()->where('company_id', $companyId)->findOrFail($data['related_id']);
        }

        return CrmActivity::query()->create([
            'company_id' => $companyId,
            'activityable_type' => $related,
            'activityable_id' => $data['related_id'] ?? null,
            'owner_id' => $data['owner_id'] ?? $user->id,
            'type' => $data['type'],
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'due_at' => $data['due_at'] ?? null,
            'status' => $data['status'] ?? 'PENDING',
            'priority' => $data['priority'] ?? 'MEDIUM',
            'outcome' => $data['outcome'] ?? null,
            'completed_at' => ($data['status'] ?? 'PENDING') === 'COMPLETED' ? now() : null,
            'created_by' => $user->id,
        ]);
    }
}

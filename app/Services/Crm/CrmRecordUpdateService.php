<?php

namespace App\Services\Crm;

use App\Exceptions\CrmException;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CrmRecordUpdateService
{
    /** @param array<string, mixed> $changes */
    public function updateLead(string $companyId, User $user, string $leadId, array $changes): CrmLead
    {
        return DB::transaction(function () use ($companyId, $user, $leadId, $changes): CrmLead {
            $lead = CrmLead::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($leadId);
            if ($lead->status === 'CONVERTED') {
                throw new CrmException('CRM_LEAD_CONVERTED', 'A converted lead cannot be edited by an AI proposal.', 409);
            }
            $lead->update([...$changes, 'updated_by' => $user->id]);

            return $lead->fresh();
        });
    }

    /** @param array<string, mixed> $changes */
    public function updateDeal(string $companyId, User $user, string $dealId, array $changes): CrmDeal
    {
        return DB::transaction(function () use ($companyId, $user, $dealId, $changes): CrmDeal {
            $deal = CrmDeal::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($dealId);
            if ($deal->status !== 'OPEN') {
                throw new CrmException('CRM_DEAL_CLOSED', 'A closed deal cannot be edited by an AI proposal.', 409);
            }
            $deal->update([...$changes, 'updated_by' => $user->id]);

            return $deal->fresh();
        });
    }
}

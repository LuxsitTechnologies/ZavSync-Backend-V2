<?php

namespace App\Services\Ai;

use App\Models\AiProviderReconciliation;
use App\Models\AiUsageRecord;
use App\Models\User;

class ProviderBillingReconciliationService
{
    public function current(string $companyId, string $provider, string $from, string $to): AiProviderReconciliation
    {
        $internal = (int) AiUsageRecord::query()->where('company_id', $companyId)->where('provider', $provider)->whereBetween('occurred_at', ["{$from} 00:00:00", "{$to} 23:59:59"])->sum('cost_minor');

        $record = AiProviderReconciliation::query()->firstOrCreate(['company_id' => $companyId, 'provider' => $provider, 'period_start' => $from, 'period_end' => $to], ['internal_cost_minor' => $internal, 'provider_cost_minor' => null, 'difference_minor' => null, 'status' => 'NOT_AVAILABLE']);
        if ($record->internal_cost_minor !== $internal) {
            $difference = $record->provider_cost_minor === null ? null : $record->provider_cost_minor - $internal;
            $record->update(['internal_cost_minor' => $internal, 'difference_minor' => $difference, 'status' => $record->provider_cost_minor === null ? 'NOT_AVAILABLE' : ($difference === 0 ? 'RECONCILED' : 'DIFFERENCE')]);
        }

        return $record->fresh();
    }

    public function import(string $companyId, User $user, string $provider, string $from, string $to, int $providerCostMinor, ?string $reference): AiProviderReconciliation
    {
        $record = $this->current($companyId, $provider, $from, $to);
        $difference = $providerCostMinor - $record->internal_cost_minor;
        $record->update(['imported_by' => $user->id, 'provider_cost_minor' => $providerCostMinor, 'difference_minor' => $difference, 'status' => $difference === 0 ? 'RECONCILED' : 'DIFFERENCE', 'provider_reference' => $reference, 'reconciled_at' => now()]);

        return $record->fresh();
    }
}

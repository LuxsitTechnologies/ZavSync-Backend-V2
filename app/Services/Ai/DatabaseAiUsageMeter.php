<?php

namespace App\Services\Ai;

use App\Contracts\AiUsageMeter;
use App\Models\AiUsageRecord;
use App\Services\Platform\EntitlementService;

class DatabaseAiUsageMeter implements AiUsageMeter
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function assertWithinLimit(string $companyId, string $operation): void
    {
        $dailyRequests = AiUsageRecord::query()->where('company_id', $companyId)->whereDate('occurred_at', today())->count();
        $monthly = AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $this->entitlements->assertWithinLimit($companyId, 'daily_ai_requests', $dailyRequests);
        $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_tokens', (int) (clone $monthly)->sum('input_tokens') + (int) (clone $monthly)->sum('output_tokens'));
        $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_cost_minor', (int) (clone $monthly)->sum('cost_minor'));
    }

    public function record(string $companyId, ?int $userId, array $usage): AiUsageRecord
    {
        $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_tokens', (int) AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('input_tokens') + (int) AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('output_tokens'), (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0));
        $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_cost_minor', (int) AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('cost_minor'), (int) ($usage['cost_minor'] ?? 0));

        return AiUsageRecord::query()->create([
            'company_id' => $companyId,
            'user_id' => $userId,
            'ai_conversation_id' => $usage['conversation_id'] ?? null,
            'ai_message_id' => $usage['message_id'] ?? null,
            'provider' => $usage['provider'],
            'model' => $usage['model'],
            'operation' => $usage['operation'],
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cost_minor' => (int) ($usage['cost_minor'] ?? 0),
            'metadata' => $usage['metadata'] ?? [],
            'occurred_at' => now(),
        ]);
    }
}

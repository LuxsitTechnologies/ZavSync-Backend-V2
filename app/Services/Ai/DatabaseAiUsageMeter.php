<?php

namespace App\Services\Ai;

use App\Contracts\AiUsageMeter;
use App\Exceptions\PlatformException;
use App\Models\AiProviderConfiguration;
use App\Models\AiUsageRecord;
use App\Models\Company;
use App\Services\Platform\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseAiUsageMeter implements AiUsageMeter
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function assertWithinLimit(string $companyId, string $operation): void
    {
        $dailyRequests = AiUsageRecord::query()->where('company_id', $companyId)->whereDate('occurred_at', today())->count();
        $monthly = AiUsageRecord::query()->where('company_id', $companyId)->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()]);
        $this->entitlements->assertWithinLimit($companyId, 'daily_ai_requests', $dailyRequests);
        $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_tokens', (int) (clone $monthly)->sum('input_tokens') + (int) (clone $monthly)->sum('output_tokens') + $this->pendingTokens($companyId));
        $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_cost_minor', (int) (clone $monthly)->sum('cost_minor'));
    }

    public function reserveChat(string $companyId, string $operation, AiChatRequest $request, AiProviderConfiguration $configuration): ?string
    {
        return $this->reserve($companyId, $operation,
            strlen(json_encode($request, JSON_THROW_ON_ERROR)) + $configuration->maximumOutputTokens() + 2048);
    }

    public function reserveEmbedding(string $companyId, string $operation, array $texts): ?string
    {
        return $this->reserve($companyId, $operation, strlen(json_encode($texts, JSON_THROW_ON_ERROR)) + 2048);
    }

    public function release(?string $reservationId): void
    {
        if ($reservationId === null) {
            return;
        }
        $companyId = DB::table('ai_usage_reservations')->where('id', $reservationId)->value('company_id');
        if ($companyId === null) {
            return;
        }
        DB::transaction(function () use ($reservationId, $companyId): void {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            DB::table('ai_usage_reservations')->where('id', $reservationId)->where('company_id', $companyId)
                ->where('status', 'PENDING')->update(['status' => 'RELEASED', 'updated_at' => now()]);
        });
    }

    public function record(string $companyId, ?int $userId, array $usage, ?string $reservationId = null): AiUsageRecord
    {
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        $costMinor = (int) ($usage['cost_minor'] ?? 0);
        if ($inputTokens < 0 || $outputTokens < 0 || $inputTokens > PHP_INT_MAX - $outputTokens
            || $costMinor < 0 || $inputTokens > 4_294_967_295 || $outputTokens > 4_294_967_295) {
            throw new PlatformException('AI_USAGE_INVALID', 'AI provider usage is outside the supported range.', 409);
        }
        $tokens = $inputTokens + $outputTokens;
        $managed = Company::query()->whereKey($companyId)->value('billing_policy_applies');
        if ($managed && $reservationId === null && $tokens > 0) {
            throw new PlatformException('AI_USAGE_RESERVATION_REQUIRED', 'AI usage must have a company quota reservation.', 409);
        }

        if ($managed && $reservationId !== null) {
            $usageHash = hash('sha256', json_encode([$userId, $usage['conversation_id'] ?? null,
                $usage['message_id'] ?? null, $usage['provider'], $usage['model'], $usage['operation'],
                $inputTokens, $outputTokens, $costMinor], JSON_THROW_ON_ERROR));
            [$record, $exceeded] = DB::transaction(function () use ($companyId, $userId, $usage, $reservationId, $tokens, $usageHash): array {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $reservation = DB::table('ai_usage_reservations')->where('company_id', $companyId)
                    ->where('id', $reservationId)->lockForUpdate()->first();
                if ($reservation !== null && ($reservation->operation !== $usage['operation']
                    || ($reservation->usage_hash !== null && ! hash_equals($reservation->usage_hash, $usageHash)))) {
                    throw new PlatformException('AI_USAGE_CONFLICT', 'The AI quota reservation belongs to different usage.', 409);
                }
                if ($reservation === null || $reservation->status !== 'PENDING') {
                    if ($reservation !== null && in_array($reservation->status, ['SETTLED', 'EXCEEDED'], true)) {
                        return [AiUsageRecord::query()->where('company_id', $companyId)
                            ->findOrFail($reservation->usage_record_id), $reservation->status === 'EXCEEDED'];
                    }
                    throw new PlatformException('AI_USAGE_RESERVATION_INVALID', 'The AI quota reservation is no longer valid.', 409);
                }
                if (CarbonImmutable::parse($reservation->expires_at)->isPast()) {
                    throw new PlatformException('AI_USAGE_RESERVATION_EXPIRED', 'The AI quota reservation has expired.', 409);
                }
                $record = $this->createRecord($companyId, $userId, $usage);
                $exceeded = $tokens > (int) $reservation->reserved_tokens;
                DB::table('ai_usage_reservations')->where('id', $reservationId)->update([
                    'status' => $exceeded ? 'EXCEEDED' : 'SETTLED', 'actual_tokens' => $tokens,
                    'usage_hash' => $usageHash, 'usage_record_id' => $record->id, 'updated_at' => now(),
                ]);

                return [$record, $exceeded];
            });
            if ($exceeded) {
                throw new PlatformException('AI_PROVIDER_USAGE_EXCEEDED_RESERVATION', 'The AI provider reported usage beyond the reserved token budget.', 409);
            }

            return $record;
        }

        if (! $managed) {
            $monthly = AiUsageRecord::query()->where('company_id', $companyId)
                ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()]);
            $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_tokens',
                (int) (clone $monthly)->sum('input_tokens') + (int) (clone $monthly)->sum('output_tokens'), $tokens);
            $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_cost_minor',
                (int) (clone $monthly)->sum('cost_minor'), $costMinor);
        }

        return $this->createRecord($companyId, $userId, $usage);
    }

    private function reserve(string $companyId, string $operation, int $maximumTokens): ?string
    {
        if (! Company::query()->whereKey($companyId)->value('billing_policy_applies')) {
            $this->assertWithinLimit($companyId, $operation);

            return null;
        }
        if ($maximumTokens < 1 || $maximumTokens > 4_294_967_295) {
            throw new PlatformException('AI_USAGE_INVALID', 'AI request is outside the supported token budget.', 409);
        }

        return DB::transaction(function () use ($companyId, $operation, $maximumTokens): string {
            Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
            $periodStart = today()->startOfMonth()->toDateString();
            DB::table('ai_usage_reservations')->where('company_id', $companyId)->where('period_start', $periodStart)
                ->where('status', 'PENDING')->where('expires_at', '<=', now())
                ->update(['status' => 'EXPIRED', 'updated_at' => now()]);
            $monthly = AiUsageRecord::query()->where('company_id', $companyId)
                ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()]);
            $used = (int) (clone $monthly)->sum('input_tokens') + (int) (clone $monthly)->sum('output_tokens');
            $pending = $this->pendingTokens($companyId);
            if ($used > PHP_INT_MAX - $pending || $used + $pending > PHP_INT_MAX - $maximumTokens) {
                throw new PlatformException('AI_USAGE_INVALID', 'AI usage is outside the supported range.', 409);
            }
            $this->entitlements->assertWithinLimit($companyId, 'monthly_ai_tokens', $used + $pending, $maximumTokens);
            $this->entitlements->assertWithinLimit($companyId, 'daily_ai_requests',
                AiUsageRecord::query()->where('company_id', $companyId)->whereDate('occurred_at', today())->count());
            $id = (string) Str::uuid();
            DB::table('ai_usage_reservations')->insert([
                'id' => $id, 'company_id' => $companyId, 'period_start' => $periodStart,
                'operation' => $operation, 'status' => 'PENDING', 'reserved_tokens' => $maximumTokens,
                'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $id;
        });
    }

    private function pendingTokens(string $companyId): int
    {
        return (int) DB::table('ai_usage_reservations')->where('company_id', $companyId)
            ->where('period_start', today()->startOfMonth()->toDateString())
            ->where('status', 'PENDING')->where('expires_at', '>', now())->sum('reserved_tokens');
    }

    /** @param array<string, mixed> $usage */
    private function createRecord(string $companyId, ?int $userId, array $usage): AiUsageRecord
    {
        return AiUsageRecord::query()->create([
            'company_id' => $companyId, 'user_id' => $userId,
            'ai_conversation_id' => $usage['conversation_id'] ?? null,
            'ai_message_id' => $usage['message_id'] ?? null,
            'provider' => $usage['provider'], 'model' => $usage['model'], 'operation' => $usage['operation'],
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cost_minor' => (int) ($usage['cost_minor'] ?? 0),
            'metadata' => $usage['metadata'] ?? [], 'occurred_at' => now(),
        ]);
    }
}

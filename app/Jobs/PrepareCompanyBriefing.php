<?php

namespace App\Jobs;

use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\ScheduledIntelligenceRun;
use App\Services\Ai\ManagementBriefingService;
use App\Services\Platform\EntitlementService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PrepareCompanyBriefing implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $companyId, public readonly string $period, public readonly string $idempotencyKey)
    {
        $this->onQueue('ai');
    }

    /**
     * Execute the job.
     */
    public function handle(ManagementBriefingService $briefings, EntitlementService $entitlements): void
    {
        $run = ScheduledIntelligenceRun::query()->firstOrCreate(['company_id' => $this->companyId, 'idempotency_key' => $this->idempotencyKey], ['run_type' => 'BRIEFING', 'status' => 'PENDING']);
        if ($run->status === 'COMPLETED') {
            return;
        }
        $run->update(['status' => 'RUNNING', 'started_at' => now()]);
        try {
            if (! in_array('ai', $entitlements->enabledModules($this->companyId), true)) {
                $run->update(['status' => 'COMPLETED', 'metrics' => ['briefings' => 0, 'skipped' => true, 'skip_reason' => 'MODULE_NOT_ENTITLED'], 'completed_at' => now()]);

                return;
            }
            $count = 0;
            $withAi = (bool) CompanySetting::query()->where('company_id', $this->companyId)->value('ai_scheduled_intelligence_enabled');
            CompanyUser::query()->where('company_id', $this->companyId)->where('is_active', true)->with('user')->orderBy('user_id')->limit(100)->get()->each(function (CompanyUser $membership) use ($briefings, &$count, $withAi): void {
                if ($membership->user->hasCompanyPermission($this->companyId, 'intelligence.briefings.view')) {
                    $briefings->prepare($this->companyId, $membership->user, $this->period, $withAi);
                    $count++;
                }
            });
            $run->update(['status' => 'COMPLETED', 'metrics' => ['briefings' => $count, 'ai_enrichment_enabled' => $withAi], 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'FAILED', 'failure_code' => class_basename($exception), 'failure_message' => 'The scheduled briefing failed. Review sanitized operational logs.', 'completed_at' => now()]);
            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return $this->companyId.'|'.$this->period.'|'.$this->idempotencyKey;
    }
}

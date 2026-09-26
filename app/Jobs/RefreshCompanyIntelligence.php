<?php

namespace App\Jobs;

use App\Models\ScheduledIntelligenceRun;
use App\Services\Ai\AnomalyDetectionService;
use App\Services\Ai\ForecastIntelligenceService;
use App\Services\Ai\OperationalSignalService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RefreshCompanyIntelligence implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $companyId, public readonly string $idempotencyKey)
    {
        $this->onQueue('ai');
    }

    /**
     * Execute the job.
     */
    public function handle(OperationalSignalService $signals, AnomalyDetectionService $anomalies, ForecastIntelligenceService $forecasts): void
    {
        $run = ScheduledIntelligenceRun::query()->firstOrCreate(['company_id' => $this->companyId, 'idempotency_key' => $this->idempotencyKey], ['run_type' => 'REFRESH', 'status' => 'PENDING']);
        if ($run->status === 'COMPLETED') {
            return;
        }
        $run->update(['status' => 'RUNNING', 'started_at' => now(), 'failure_code' => null, 'failure_message' => null]);
        try {
            $anomalyResults = $anomalies->refresh($this->companyId);
            $forecastResults = $forecasts->refresh($this->companyId);
            $signalResult = $signals->refresh($this->companyId);
            $run->update(['status' => 'COMPLETED', 'metrics' => ['signals' => $signalResult, 'anomalies' => count($anomalyResults), 'forecasts' => count($forecastResults)], 'completed_at' => now()]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'FAILED', 'failure_code' => class_basename($exception), 'failure_message' => 'The intelligence refresh failed. Review sanitized operational logs.', 'completed_at' => now()]);
            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return $this->companyId.'|'.$this->idempotencyKey;
    }
}

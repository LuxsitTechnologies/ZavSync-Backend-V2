<?php

namespace App\Jobs;

use App\Services\Ai\KnowledgeIngestionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestKnowledgeSource implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    /**
     * Create a new job instance.
     */
    public function __construct(public readonly string $runId)
    {
        $this->onQueue('ai');
    }

    /**
     * Execute the job.
     */
    public function handle(KnowledgeIngestionService $ingestion): void
    {
        $ingestion->ingest($this->runId);
    }

    public function uniqueId(): string
    {
        return $this->runId;
    }
}

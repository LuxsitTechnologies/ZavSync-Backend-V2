<?php

namespace App\Jobs;

use App\Services\Outreach\OutreachEventService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessOutreachEvent implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array{provider_event_id:string,provider_message_id:string,type:string,occurred_at:string,payload:array<string,mixed>} $event */
    public function __construct(public readonly string $companyId, public readonly array $event) {}

    /**
     * Execute the job.
     */
    public function handle(OutreachEventService $events): void
    {
        $events->process($this->companyId, $this->event);
    }

    public function uniqueId(): string
    {
        return $this->companyId.':'.$this->event['provider_event_id'];
    }
}

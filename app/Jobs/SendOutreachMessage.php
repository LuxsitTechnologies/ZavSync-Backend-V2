<?php

namespace App\Jobs;

use App\Services\Outreach\MessageDeliveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendOutreachMessage implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $messageId) {}

    /**
     * Execute the job.
     */
    public function handle(MessageDeliveryService $delivery): void
    {
        $delivery->deliver($this->messageId);
    }

    public function uniqueId(): string
    {
        return $this->messageId;
    }
}

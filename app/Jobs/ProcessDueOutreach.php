<?php

namespace App\Jobs;

use App\Models\OutreachMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ProcessDueOutreach implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct() {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        OutreachMessage::query()->where('state', 'SCHEDULED')->where('scheduled_at', '<=', now())
            ->whereHas('sequence', fn ($query) => $query->where('status', 'ACTIVE'))
            ->whereHas('enrollment', fn ($query) => $query->where('status', 'ACTIVE'))
            ->orderBy('scheduled_at')->orderBy('id')->limit(100)->pluck('id')->each(function (string $messageId): void {
                $queued = DB::transaction(function () use ($messageId): bool {
                    $message = OutreachMessage::query()->lockForUpdate()->find($messageId);
                    if ($message === null || $message->state !== 'SCHEDULED' || $message->scheduled_at->isFuture()) {
                        return false;
                    }
                    $message->update(['state' => 'QUEUED', 'queued_at' => now()]);

                    return true;
                });
                if ($queued) {
                    SendOutreachMessage::dispatch($messageId)->afterCommit()->onQueue('outreach');
                }
            });
    }
}

<?php

namespace App\Jobs;

use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverPlatformNotification implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 30;

    public function __construct(public readonly string $notificationId) {}

    public function uniqueId(): string
    {
        return $this->notificationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function (): void {
            $notification = PlatformNotification::query()->lockForUpdate()->find($this->notificationId);
            if ($notification === null || $notification->delivered_at !== null) {
                return;
            }
            if ($notification->channel === 'EMAIL') {
                $recipient = User::query()->findOrFail($notification->recipient_id);
                Mail::raw($notification->message, fn ($mail) => $mail->to($recipient->email)->subject($notification->title));
            }
            $notification->update(['delivery_state' => 'DELIVERED', 'delivered_at' => now(), 'failed_at' => null]);
        });
    }

    public function failed(Throwable $exception): void
    {
        PlatformNotification::query()->whereKey($this->notificationId)->update(['delivery_state' => 'FAILED', 'failed_at' => now()]);
    }
}

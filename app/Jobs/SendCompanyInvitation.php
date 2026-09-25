<?php

namespace App\Jobs;

use App\Models\CompanyInvitation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendCompanyInvitation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly string $invitationId, public readonly string $encryptedToken) {}

    public function uniqueId(): string
    {
        return $this->invitationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::transaction(function (): void {
            $invitation = CompanyInvitation::query()->with('company')->lockForUpdate()->find($this->invitationId);
            if ($invitation === null || $invitation->status !== 'PENDING' || $invitation->expires_at->isPast() || $invitation->emailed_at !== null) {
                return;
            }
            $url = rtrim((string) config('platform.frontend_url'), '/').'/set-password?invitation='.rawurlencode(Crypt::decryptString($this->encryptedToken));
            Mail::raw("You were invited to {$invitation->company->name}. Accept the invitation: {$url}", fn ($mail) => $mail->to($invitation->email)->subject("Invitation to {$invitation->company->name}"));
            $invitation->update(['emailed_at' => now()]);
        });
    }
}

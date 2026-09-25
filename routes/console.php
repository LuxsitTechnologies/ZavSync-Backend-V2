<?php

use App\Jobs\ProcessDueOutreach;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('platform:expire-invitations', function (): void {
    $count = DB::table('company_invitations')->where('status', 'PENDING')->where('expires_at', '<=', now())->update(['status' => 'EXPIRED', 'updated_at' => now()]);
    $this->info("Expired {$count} invitation(s).");
})->purpose('Expire pending company invitations past their deadline');

Artisan::command('platform:evaluate-subscriptions', function (): void {
    $count = DB::table('subscriptions')->where(function ($query): void {
        $query->where(function ($trials): void {
            $trials->where('status', 'TRIALING')->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now());
        })->orWhere(function ($ending): void {
            $ending->whereIn('status', ['TRIALING', 'ACTIVE'])->whereNotNull('ends_at')->where('ends_at', '<=', now());
        });
    })->update(['status' => 'EXPIRED', 'updated_at' => now()]);
    $this->info("Expired {$count} subscription(s).");
})->purpose('Evaluate subscription expiry dates');

Schedule::command('platform:expire-invitations')->hourly()->withoutOverlapping();
Schedule::command('platform:evaluate-subscriptions')->daily()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily()->withoutOverlapping();
Schedule::job(new ProcessDueOutreach, 'outreach')->everyMinute()->withoutOverlapping(5)->onOneServer();

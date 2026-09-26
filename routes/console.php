<?php

use App\Jobs\PrepareCompanyBriefing;
use App\Jobs\ProcessDueOutreach;
use App\Jobs\RefreshCompanyIntelligence;
use App\Models\AiActionProposal;
use App\Models\AiConversation;
use App\Models\AiUsageRecord;
use App\Models\Company;
use App\Models\CompanySetting;
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

Artisan::command('ai:expire-proposals', function (): void {
    $count = AiActionProposal::query()->whereIn('status', ['PENDING', 'APPROVED'])->where('expires_at', '<=', now())->update(['status' => 'EXPIRED', 'updated_at' => now()]);
    $this->info("Expired {$count} AI action proposal(s).");
})->purpose('Expire AI action proposals past their approval window');

Artisan::command('ai:prune-data', function (): void {
    $conversations = 0;
    $usageRecords = 0;
    CompanySetting::query()->select(['company_id', 'ai_conversation_retention_days', 'ai_usage_retention_days'])->each(function (CompanySetting $settings) use (&$conversations, &$usageRecords): void {
        $conversations += AiConversation::query()->where('company_id', $settings->company_id)->whereNotNull('archived_at')->where('archived_at', '<=', now()->subDays($settings->ai_conversation_retention_days))->delete();
        $usageRecords += AiUsageRecord::query()->where('company_id', $settings->company_id)->where('occurred_at', '<=', now()->subDays($settings->ai_usage_retention_days))->delete();
    });
    $this->info("Pruned {$conversations} AI conversation(s) and {$usageRecords} usage record(s).");
})->purpose('Apply company AI conversation and usage retention policies');

Artisan::command('intelligence:dispatch-refresh', function (): void {
    Company::query()->where('is_active', true)->whereHas('entitlements', fn ($query) => $query->where('module_key', 'ai')->where('is_enabled', true))->orderBy('id')->chunk(100, function ($companies): void {
        $companies->each(fn (Company $company) => RefreshCompanyIntelligence::dispatch($company->id, 'scheduled:'.now()->format('Y-m-d-H'))->onQueue('ai'));
    });
    $this->info('Queued bounded company intelligence refresh jobs.');
})->purpose('Dispatch company-scoped operational intelligence refresh jobs');

Artisan::command('intelligence:dispatch-briefings', function (): void {
    Company::query()->where('is_active', true)->whereHas('entitlements', fn ($query) => $query->where('module_key', 'ai')->where('is_enabled', true))->orderBy('id')->chunk(100, function ($companies): void {
        $companies->each(fn (Company $company) => PrepareCompanyBriefing::dispatch($company->id, 'TODAY', 'scheduled:'.now()->toDateString())->onQueue('ai'));
    });
    $this->info('Queued bounded company management briefing jobs.');
})->purpose('Dispatch company-scoped deterministic management briefings');

Schedule::command('platform:expire-invitations')->hourly()->withoutOverlapping();
Schedule::command('platform:evaluate-subscriptions')->daily()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->daily()->withoutOverlapping();
Schedule::job(new ProcessDueOutreach, 'outreach')->everyMinute()->withoutOverlapping(5)->onOneServer();
Schedule::command('ai:expire-proposals')->everyFiveMinutes()->withoutOverlapping(5)->onOneServer();
Schedule::command('ai:prune-data')->daily()->withoutOverlapping()->onOneServer();
Schedule::command('intelligence:dispatch-refresh')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('intelligence:dispatch-briefings')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

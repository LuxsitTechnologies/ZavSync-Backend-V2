<?php

namespace App\Services\Platform;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingNoticeService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function notify(Subscription $subscription, string $phase): void
    {
        $definition = match ($phase) {
            'INITIAL' => ['billing.payment_failed', 'Subscription payment needs attention',
                'A subscription payment failed. Update billing before the seven-day grace period ends.'],
            'REMINDER' => ['billing.payment_reminder', 'Subscription payment reminder',
                'A subscription payment remains unresolved. Review billing to avoid read-only access.'],
            'FINAL' => ['billing.payment_final_notice', 'Final subscription payment notice',
                'The payment grace period is ending. Review billing now to avoid read-only access.'],
            default => throw new \InvalidArgumentException('Unsupported billing notice phase.'),
        };
        DB::transaction(function () use ($subscription, $phase, $definition): void {
            $inserted = DB::table('billing_notices')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'company_id' => $subscription->company_id,
                'subscription_id' => $subscription->id, 'notice_type' => $phase,
                'notice_date' => now('UTC')->toDateString(), 'created_at' => now(),
            ]);
            if ($inserted === 0) {
                return;
            }
            $company = Company::query()->findOrFail($subscription->company_id);
            $memberships = CompanyUser::query()->with('user')->where('company_id', $company->id)
                ->where('is_active', true)->whereNull('suspended_at')->get();
            foreach ($memberships as $membership) {
                $user = $membership->user;
                if ($user === null || ($user->id !== $company->created_by
                    && ! $user->hasCompanyPermission($company->id, 'billing.manage'))) {
                    continue;
                }
                $this->notifications->create($company->id, $user->id, $definition[0], $definition[1], $definition[2],
                    ['subscription_id' => $subscription->id], '/billing');
            }
        });
    }
}

<?php

namespace App\Services\Ai;

use App\Contracts\CalendarGateway;
use App\Exceptions\PlatformException;
use App\Models\CalendarEvent;
use App\Models\CalendarProviderConnection;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\User;
use App\Services\Platform\PlatformAccessService;

class CalendarIntelligenceService
{
    public function __construct(private readonly CalendarGateway $gateway, private readonly PlatformAccessService $access) {}

    /** @return array<string,mixed> */
    public function capability(string $companyId, User $user): array
    {
        $connection = CalendarProviderConnection::query()->where('company_id', $companyId)->where('user_id', $user->id)->first();

        return ['available' => $this->gateway->available(), 'status' => $connection?->status ?? 'NOT_CONFIGURED', 'provider' => $connection?->provider, 'identity_email' => $connection?->identity_email, 'last_synced_at' => $connection?->last_synced_at, 'message' => $this->gateway->available() ? null : 'Google and Microsoft calendar OAuth are not configured for this deployment.'];
    }

    /** @return array<string,mixed> */
    public function meetingContext(string $companyId, User $user, string $eventId): array
    {
        $event = CalendarEvent::query()->where('company_id', $companyId)->whereHas('connection', fn ($query) => $query->where('user_id', $user->id))->findOrFail($eventId);
        $attendeeEmails = collect($event->attendees ?? [])->pluck('email')->filter()->values();
        $context = ['event' => $event->only(['id', 'title', 'starts_at', 'ends_at', 'status']), 'crm' => [], 'financial' => null];
        if ($user->hasCompanyPermission($companyId, 'crm.view')) {
            $contacts = CrmContact::query()->where('company_id', $companyId)->whereIn('email', $attendeeEmails)->with('account:id,name')->limit(20)->get();
            $accountIds = $contacts->pluck('account_id')->filter();
            $context['crm'] = ['contacts' => $contacts->map->only(['id', 'first_name', 'last_name', 'email', 'account_id']), 'accounts' => CrmAccount::query()->where('company_id', $companyId)->whereKey($accountIds)->get(['id', 'name']), 'deals' => CrmDeal::query()->where('company_id', $companyId)->whereIn('account_id', $accountIds)->where('status', 'OPEN')->get(['id', 'title', 'amount', 'currency', 'probability_bps', 'expected_close_date'])];
        }
        if ($user->hasCompanyPermission($companyId, 'accounting.view')) {
            $context['financial'] = ['available' => true, 'note' => 'Financial context is permission-gated and should be requested through an authorized read-only tool.'];
        }

        return $context;
    }

    public function synchronize(string $companyId, User $user): array
    {
        $this->access->authorize($user, $companyId, 'intelligence.calendar.manage');
        $connection = CalendarProviderConnection::query()->where('company_id', $companyId)->where('user_id', $user->id)->first();
        if ($connection === null) {
            throw new PlatformException('CALENDAR_PROVIDER_NOT_CONFIGURED', 'Connect a supported calendar provider before synchronization.', 409);
        }

        return $this->gateway->synchronize($connection);
    }
}

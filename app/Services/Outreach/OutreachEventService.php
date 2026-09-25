<?php

namespace App\Services\Outreach;

use App\Contracts\OutboundEmailGateway;
use App\Exceptions\OutreachException;
use App\Jobs\ProcessOutreachEvent;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\EmailProviderConnection;
use App\Models\OutreachMessage;
use App\Models\OutreachMessageEvent;
use App\Models\OutreachMessageLink;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OutreachEventService
{
    public function __construct(private readonly OutboundEmailGateway $gateway, private readonly SuppressionService $suppressions) {}

    public function receiveWebhook(EmailProviderConnection $connection, Request $request): int
    {
        if (! $this->gateway->webhookIsAuthentic($connection, $request)) {
            SecurityEvent::query()->create(['company_id' => $connection->company_id, 'type' => 'OUTREACH_WEBHOOK_REJECTED', 'result' => 'DENIED', 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000), 'correlation_id' => $request->attributes->get('correlation_id'), 'metadata' => ['provider_connection_id' => $connection->id, 'provider_type' => $connection->provider_type]]);
            throw new OutreachException('INVALID_WEBHOOK_SIGNATURE', 'Webhook signature is invalid.', 401);
        }
        $events = $this->gateway->normalizeWebhook($connection, $request->all());
        foreach ($events as $event) {
            ProcessOutreachEvent::dispatch($connection->company_id, $event)->afterCommit()->onQueue('outreach');
        }

        return count($events);
    }

    public function synchronizeReplies(EmailProviderConnection $connection): int
    {
        $events = $this->gateway->synchronizeReplies($connection);
        foreach ($events as $event) {
            ProcessOutreachEvent::dispatch($connection->company_id, [...$event, 'type' => 'REPLIED'])->afterCommit()->onQueue('outreach');
        }

        return count($events);
    }

    /** @param array{provider_event_id:string,provider_message_id:string,type:string,occurred_at:string,payload:array<string,mixed>} $event */
    public function process(string $companyId, array $event): OutreachMessageEvent
    {
        return DB::transaction(function () use ($companyId, $event): OutreachMessageEvent {
            $existing = OutreachMessageEvent::query()->where('company_id', $companyId)->where('provider_event_id', $event['provider_event_id'])->first();
            if ($existing !== null) {
                return $existing;
            }
            $message = OutreachMessage::query()->with(['enrollment', 'sequence'])->where('company_id', $companyId)->where(fn ($query) => $query->where('provider_message_id', $event['provider_message_id'])->orWhere('stable_message_id', $event['provider_message_id']))->lockForUpdate()->firstOrFail();
            $type = mb_strtoupper($event['type']);
            $record = OutreachMessageEvent::query()->create(['company_id' => $companyId, 'message_id' => $message->id, 'provider_event_id' => $event['provider_event_id'], 'type' => $type, 'occurred_at' => $event['occurred_at'], 'payload' => $event['payload'], 'processed_at' => now()]);
            $this->apply($message, $type);
            $this->syncCrmActivity($message, $record);

            return $record;
        });
    }

    public function open(string $messageId, string $token): void
    {
        $message = OutreachMessage::query()->findOrFail($messageId);
        if (! hash_equals($message->tracking_token_hash, hash('sha256', $token))) {
            abort(404);
        }
        OutreachMessageEvent::query()->firstOrCreate(['company_id' => $message->company_id, 'message_id' => $message->id, 'provider_event_id' => 'open:'.$message->id, 'type' => 'OPENED'], ['occurred_at' => now(), 'payload' => [], 'processed_at' => now()]);
    }

    public function click(string $token): string
    {
        return DB::transaction(function () use ($token): string {
            $link = OutreachMessageLink::query()->with('message')->where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            $link->update(['first_clicked_at' => $link->first_clicked_at ?? now(), 'click_count' => $link->click_count + 1]);
            OutreachMessageEvent::query()->firstOrCreate(['company_id' => $link->company_id, 'message_id' => $link->message_id, 'provider_event_id' => 'click:'.$link->id, 'type' => 'CLICKED'], ['occurred_at' => now(), 'payload' => ['link_id' => $link->id], 'processed_at' => now()]);

            return $link->destination_url;
        });
    }

    public function unsubscribe(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $message = OutreachMessage::query()->with('enrollment')->where('unsubscribe_token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            $this->suppressions->suppress($message->company_id, $message->to_email, 'UNSUBSCRIBE', 'PUBLIC_LINK');
            $message->enrollment->update(['status' => 'SUPPRESSED', 'ended_at' => now(), 'next_action_at' => null]);
            $this->cancelRemaining($message);
            OutreachMessageEvent::query()->firstOrCreate(['company_id' => $message->company_id, 'message_id' => $message->id, 'provider_event_id' => 'unsubscribe:'.$message->id, 'type' => 'UNSUBSCRIBED'], ['occurred_at' => now(), 'payload' => [], 'processed_at' => now()]);
        });
    }

    private function apply(OutreachMessage $message, string $type): void
    {
        if ($type === 'DELIVERED') {
            $message->update(['state' => 'DELIVERED', 'delivered_at' => now()]);
        }
        if ($type === 'FAILED') {
            $message->update(['state' => 'FAILED', 'failed_at' => now()]);
        }
        if (in_array($type, ['BOUNCED', 'HARD_BOUNCE'], true)) {
            $message->update(['state' => 'BOUNCED', 'bounced_at' => now()]);
            $this->suppressions->suppress($message->company_id, $message->to_email, 'HARD_BOUNCE', 'PROVIDER_EVENT');
            $message->enrollment->update(['status' => 'SUPPRESSED', 'ended_at' => now(), 'next_action_at' => null]);
            $this->cancelRemaining($message);
        }
        if ($type === 'COMPLAINT') {
            $this->suppressions->suppress($message->company_id, $message->to_email, 'COMPLAINT', 'PROVIDER_EVENT');
            $message->enrollment->update(['status' => 'SUPPRESSED', 'ended_at' => now(), 'next_action_at' => null]);
            $this->cancelRemaining($message);
        }
        if ($type === 'REPLIED') {
            $message->update(['replied_at' => now()]);
            if ($message->sequence->stop_on_reply) {
                $message->enrollment->update(['status' => 'REPLIED', 'ended_at' => now(), 'next_action_at' => null]);
                $this->cancelRemaining($message);
            }
        }
    }

    private function cancelRemaining(OutreachMessage $message): void
    {
        $message->enrollment->messages()->whereKeyNot($message->id)->whereIn('state', ['SCHEDULED', 'QUEUED'])->update(['state' => 'CANCELLED', 'cancelled_at' => now()]);
    }

    private function syncCrmActivity(OutreachMessage $message, OutreachMessageEvent $event): void
    {
        if (! in_array($event->type, ['DELIVERED', 'BOUNCED', 'HARD_BOUNCE', 'COMPLAINT', 'REPLIED'], true)) {
            return;
        }
        $recipient = ($message->recipient_type === 'CONTACT' ? CrmContact::query() : CrmLead::query())->where('company_id', $message->company_id)->find($message->recipient_id);
        if ($recipient === null) {
            return;
        }
        CrmActivity::query()->firstOrCreate(
            ['company_id' => $message->company_id, 'activityable_type' => $recipient->getMorphClass(), 'activityable_id' => $recipient->getKey(), 'type' => 'EMAIL', 'outcome' => 'outreach_event:'.$event->id],
            ['owner_id' => $message->sequence->owner_id ?? $message->sequence->created_by, 'subject' => $message->subject.' — '.strtolower($event->type), 'description' => 'Outreach event for '.$message->to_email, 'completed_at' => $event->occurred_at, 'status' => 'COMPLETED', 'priority' => 'MEDIUM', 'created_by' => $message->sequence->created_by],
        );
    }
}

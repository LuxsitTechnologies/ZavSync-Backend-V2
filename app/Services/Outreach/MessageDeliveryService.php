<?php

namespace App\Services\Outreach;

use App\Contracts\OutboundEmailGateway;
use App\Exceptions\OutreachException;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\OutreachMessage;
use App\Models\OutreachSendAttempt;
use App\Services\Platform\EntitlementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class MessageDeliveryService
{
    public function __construct(private readonly OutboundEmailGateway $gateway, private readonly SuppressionService $suppressions, private readonly EntitlementService $entitlements, private readonly MessagePreparationService $preparation) {}

    public function deliver(string $messageId): OutreachMessage
    {
        [$message, $attempt] = DB::transaction(function () use ($messageId): array {
            $message = OutreachMessage::query()->with(['sendingIdentity', 'providerConnection', 'sequence', 'enrollment'])->lockForUpdate()->findOrFail($messageId);
            if (in_array($message->state, ['SENT', 'DELIVERED', 'BOUNCED', 'CANCELLED', 'SUPPRESSED'], true)) {
                return [$message, null];
            }
            if ($message->state === 'SENDING' && $message->attempts()->where('status', 'STARTED')->exists()) {
                $message->attempts()->where('status', 'STARTED')->update(['status' => 'FAILED', 'error_code' => 'DELIVERY_OUTCOME_UNKNOWN', 'error_message' => 'The previous delivery outcome is unknown and was not retried automatically.', 'completed_at' => now()]);
                $message->update(['state' => 'FAILED', 'failed_at' => now(), 'failure_message' => 'Delivery outcome is unknown; reconcile with the provider before manually retrying.']);

                return [$message->fresh(), null];
            }
            if ($message->scheduled_at->isFuture()) {
                throw new OutreachException('MESSAGE_NOT_DUE', 'The message is not due yet.', 409);
            }
            if ($message->sequence->status === 'PAUSED' || $message->enrollment->status === 'PAUSED') {
                $message->update(['state' => 'SCHEDULED', 'queued_at' => null]);

                return [$message->fresh(), null];
            }
            if ($message->sequence->status !== 'ACTIVE' || $message->enrollment->status !== 'ACTIVE') {
                $message->update(['state' => 'CANCELLED', 'cancelled_at' => now()]);

                return [$message->fresh(), null];
            }
            if ($this->suppressions->isSuppressed($message->company_id, $message->to_email)) {
                $message->update(['state' => 'SUPPRESSED', 'suppressed_at' => now()]);
                $message->enrollment->update(['status' => 'SUPPRESSED', 'ended_at' => now(), 'next_action_at' => null]);

                return [$message->fresh(), null];
            }
            $identity = $message->sendingIdentity;
            if (! $identity->is_active || $identity->verification_status !== 'VERIFIED' || $message->providerConnection?->status !== 'CONNECTED') {
                throw new OutreachException('VERIFIED_SENDER_REQUIRED', 'The sending identity or provider is no longer available.', 409);
            }
            Company::query()->whereKey($message->company_id)->lockForUpdate()->firstOrFail();
            $timezone = CompanySetting::query()->where('company_id', $message->company_id)->value('timezone') ?: 'UTC';
            $start = CarbonImmutable::now($timezone)->startOfDay()->utc();
            $end = CarbonImmutable::now($timezone)->endOfDay()->utc();
            $sentToday = OutreachMessage::query()->where('company_id', $message->company_id)->whereBetween('sent_at', [$start, $end])->count();
            $this->entitlements->assertWithinLimit($message->company_id, 'daily_messages', $sentToday);
            $attemptNumber = (int) OutreachSendAttempt::query()->where('message_id', $message->id)->max('attempt_number') + 1;
            $attempt = OutreachSendAttempt::query()->create(['company_id' => $message->company_id, 'message_id' => $message->id, 'attempt_number' => $attemptNumber, 'idempotency_key' => $message->stable_message_id.':'.$attemptNumber, 'status' => 'STARTED', 'started_at' => now()]);
            $message->update(['state' => 'SENDING', 'sending_at' => now(), 'failure_message' => null]);

            return [$message->fresh(['sendingIdentity', 'providerConnection', 'sequence', 'enrollment']), $attempt];
        });
        if ($attempt === null) {
            return $message;
        }
        try {
            $result = $this->gateway->send($message->providerConnection, $message);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($message, $attempt, $exception): void {
                OutreachSendAttempt::query()->whereKey($attempt->id)->update(['status' => 'FAILED', 'error_code' => $exception instanceof OutreachException ? $exception->errorCode : class_basename($exception), 'error_message' => 'Provider delivery failed.', 'completed_at' => now()]);
                OutreachMessage::query()->whereKey($message->id)->where('state', 'SENDING')->update(['state' => 'FAILED', 'failed_at' => now(), 'failure_message' => 'Provider delivery failed.']);
            });
            if (! $exception instanceof OutreachException) {
                throw new \RuntimeException('Temporary provider delivery failure.');
            }

            return $message->fresh();
        }

        return DB::transaction(function () use ($message, $attempt, $result): OutreachMessage {
            $locked = OutreachMessage::query()->with(['enrollment', 'sequence'])->lockForUpdate()->findOrFail($message->id);
            $attempt->update(['status' => 'SUCCEEDED', 'provider_message_id' => $result->providerMessageId, 'provider_response' => Arr::only($result->response, ['status', 'code', 'request_id']), 'completed_at' => now()]);
            $locked->update(['state' => 'SENT', 'provider_message_id' => $result->providerMessageId, 'sent_at' => now()]);
            $this->syncCrmActivity($locked);
            $enrollment = $locked->enrollment;
            if ($enrollment->status === 'ACTIVE') {
                $enrollment->update(['current_step_position' => $enrollment->current_step_position + 1, 'next_action_at' => null]);
                $this->preparation->prepareNext($enrollment->fresh());
            }

            return $locked->fresh();
        });
    }

    private function syncCrmActivity(OutreachMessage $message): void
    {
        $recipient = ($message->recipient_type === 'CONTACT' ? CrmContact::query() : CrmLead::query())->where('company_id', $message->company_id)->find($message->recipient_id);
        if ($recipient === null) {
            return;
        }
        CrmActivity::query()->firstOrCreate(
            ['company_id' => $message->company_id, 'activityable_type' => $recipient->getMorphClass(), 'activityable_id' => $recipient->getKey(), 'type' => 'EMAIL', 'outcome' => 'outreach_message:'.$message->id],
            ['owner_id' => $message->sequence->created_by, 'subject' => $message->subject, 'description' => 'Outreach email sent to '.$message->to_email, 'completed_at' => now(), 'status' => 'COMPLETED', 'priority' => 'MEDIUM', 'created_by' => $message->sequence->created_by],
        );
    }
}

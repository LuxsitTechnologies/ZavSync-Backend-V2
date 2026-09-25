<?php

namespace App\Services\Outreach;

use App\Exceptions\OutreachException;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\OutreachEnrollment;
use App\Models\OutreachSequence;
use App\Services\Platform\EntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EnrollmentService
{
    public function __construct(private readonly SuppressionService $suppressions, private readonly MessagePreparationService $messages, private readonly EntitlementService $entitlements) {}

    /** @param array<string, mixed> $data @return array{enrolled:array<int, OutreachEnrollment>,failures:array<int, array{recipient_id:string,message:string,error_code:string}>} */
    public function enroll(string $companyId, int $userId, OutreachSequence $sequence, array $data): array
    {
        if ($sequence->status !== 'ACTIVE') {
            throw new OutreachException('SEQUENCE_NOT_ACTIVE', 'Only active sequences accept enrollments.', 409);
        }
        $sequence->loadMissing('sendingIdentity.connection');
        if ($sequence->sendingIdentity === null || ! $sequence->sendingIdentity->is_active || $sequence->sendingIdentity->verification_status !== 'VERIFIED' || $sequence->sendingIdentity->connection?->status !== 'CONNECTED') {
            throw new OutreachException('VERIFIED_SENDER_REQUIRED', 'A verified active identity on a connected provider is required.', 409);
        }
        $recipients = $this->recipients($companyId, $data);
        $current = OutreachEnrollment::query()->where('company_id', $companyId)->whereIn('status', ['ACTIVE', 'PAUSED'])->count();
        $additional = $recipients->filter(fn (Model $recipient): bool => ! OutreachEnrollment::query()->where('sequence_id', $sequence->id)->where('recipient_type', $data['recipient_type'])->where('recipient_id', $recipient->getKey())->exists())->count();
        $this->entitlements->assertWithinLimit($companyId, 'enrolled_recipients', $current, $additional);
        $enrolled = [];
        $foundIds = $recipients->map(fn (Model $recipient): string => (string) $recipient->getKey())->all();
        $failures = collect($data['recipient_ids'] ?? [])->map('strval')->diff($foundIds)->map(fn (string $id): array => ['recipient_id' => $id, 'message' => 'Recipient was not found in the active company.', 'error_code' => 'RECIPIENT_NOT_FOUND'])->values()->all();
        foreach ($recipients as $recipient) {
            try {
                $enrolled[] = $this->enrollOne($companyId, $userId, $sequence, $recipient, (string) $data['recipient_type'], (string) $data['idempotency_key']);
            } catch (OutreachException $exception) {
                $failures[] = ['recipient_id' => (string) $recipient->getKey(), 'message' => $exception->getMessage(), 'error_code' => $exception->errorCode];
            }
        }

        return ['enrolled' => $enrolled, 'failures' => $failures];
    }

    private function enrollOne(string $companyId, int $userId, OutreachSequence $sequence, Model $recipient, string $type, string $rootKey): OutreachEnrollment
    {
        $email = (string) $recipient->getAttribute('email');
        $key = $rootKey.':'.$type.':'.$recipient->getKey();
        $hash = hash('sha256', implode('|', [$sequence->id, $type, $recipient->getKey(), mb_strtolower($email)]));
        $existing = OutreachEnrollment::query()->where('company_id', $companyId)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            if (! hash_equals($existing->idempotency_hash, $hash)) {
                throw new OutreachException('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with different enrollment data.', 409);
            }

            return $existing->load('messages');
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OutreachException('RECIPIENT_EMAIL_INVALID', 'Recipient does not have a valid email address.');
        }
        if ($this->suppressions->isSuppressed($companyId, $email)) {
            throw new OutreachException('RECIPIENT_SUPPRESSED', 'Recipient is suppressed.', 409);
        }

        return DB::transaction(function () use ($companyId, $userId, $sequence, $recipient, $type, $email, $key, $hash): OutreachEnrollment {
            $existing = OutreachEnrollment::query()->where('company_id', $companyId)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if (! hash_equals($existing->idempotency_hash, $hash)) {
                    throw new OutreachException('IDEMPOTENCY_CONFLICT', 'The idempotency key was already used with different enrollment data.', 409);
                }

                return $existing->load('messages');
            }
            if (OutreachEnrollment::query()->where('sequence_id', $sequence->id)->where('recipient_type', $type)->where('recipient_id', $recipient->getKey())->exists()) {
                throw new OutreachException('RECIPIENT_ALREADY_ENROLLED', 'Recipient is already enrolled in this sequence.', 409);
            }
            $name = trim((string) $recipient->getAttribute('first_name').' '.(string) $recipient->getAttribute('last_name'));
            $enrollment = OutreachEnrollment::query()->create(['company_id' => $companyId, 'sequence_id' => $sequence->id, 'recipient_type' => $type, 'recipient_id' => $recipient->getKey(), 'recipient_email' => mb_strtolower($email), 'recipient_name' => $name ?: $email, 'status' => 'ACTIVE', 'current_step_position' => 1, 'idempotency_key' => $key, 'idempotency_hash' => $hash, 'enrolled_at' => now(), 'created_by' => $userId]);
            $this->messages->prepareNext($enrollment, $recipient);

            return $enrollment->fresh()->load('messages');
        });
    }

    /** @param array<string, mixed> $data @return \Illuminate\Database\Eloquent\Collection<int, CrmContact|CrmLead> */
    private function recipients(string $companyId, array $data): Collection
    {
        $type = (string) $data['recipient_type'];
        $query = ($type === 'CONTACT' ? CrmContact::query() : CrmLead::query())->where('company_id', $companyId);
        if ($type === 'CONTACT') {
            $query->where('status', 'ACTIVE');
        } else {
            $query->whereNotIn('status', ['CONVERTED', 'LOST', 'UNQUALIFIED']);
        }
        if (($data['recipient_ids'] ?? []) !== []) {
            $query->whereKey($data['recipient_ids']);
        }
        $filters = $data['filters'] ?? [];
        foreach (['owner_id', 'account_id', 'status', 'source'] as $field) {
            if (array_key_exists($field, $filters) && $filters[$field] !== null) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($type === 'LEAD' && isset($filters['min_score'])) {
            $query->where('score', '>=', (int) $filters['min_score']);
        }
        if (isset($filters['tag_id'])) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereKey($filters['tag_id']));
        }
        if ($type === 'CONTACT' && isset($filters['deal_id'])) {
            $query->whereHas('account.deals', fn (Builder $deals) => $deals->whereKey($filters['deal_id']));
        }

        return $query->orderBy('id')->limit(1000)->get();
    }
}

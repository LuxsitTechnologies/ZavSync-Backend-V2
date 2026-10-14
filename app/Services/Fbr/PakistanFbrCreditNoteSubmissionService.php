<?php

namespace App\Services\Fbr;

use App\Contracts\FbrCreditNoteGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrOutcomeUncertainException;
use App\Exceptions\FbrUnavailableException;
use App\Exceptions\PlatformException;
use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrCreditNoteAttempt;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Migration\HistoricalResponseSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PakistanFbrCreditNoteSubmissionService
{
    public function __construct(
        private readonly FbrCreditNoteGateway $gateway,
        private readonly HistoricalResponseSanitizer $sanitizer,
        private readonly AuditService $audit,
    ) {}

    public function submit(string $companyId, User $user, string $creditId, string $key): PakistanFbrCreditNote
    {
        if (! config('services.fbr.credit_note_submission_enabled', false)) {
            throw new FbrUnavailableException('FBR Credit Note transmission is not certified.');
        }

        [$attemptId, $generation, $claimed] = DB::transaction(function () use ($companyId, $user, $creditId, $key): array {
            $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->with('lines')
                ->lockForUpdate()->findOrFail($creditId);
            if ($credit->state === 'ACCEPTED') {
                return [null, null, false];
            }
            if (! in_array($credit->state, ['DRAFT', 'REJECTED', 'IN_PROGRESS'], true)) {
                throw new PlatformException('FBR_CREDIT_OUTCOME_UNCERTAIN', 'The Credit Note outcome requires authoritative reconciliation.', 409);
            }
            $hash = hash('sha256', json_encode([
                'original_reference' => $credit->original_fbr_reference_number,
                'reason' => $credit->reason,
                'lines' => $credit->lines->map(fn ($line): array => [
                    'invoice_line_id' => $line->invoice_line_id,
                    'source_snapshot' => $line->source_snapshot,
                    'total' => $line->total,
                ])->all(),
            ], JSON_THROW_ON_ERROR));
            $attempt = PakistanFbrCreditNoteAttempt::query()->where('company_id', $companyId)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($attempt !== null && ($attempt->credit_note_id !== $creditId || ! hash_equals($attempt->payload_hash, $hash))) {
                throw new PlatformException('FBR_CREDIT_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to a different Credit Note payload.', 409);
            }
            if ($attempt !== null && in_array($attempt->status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Rejected], true)) {
                return [null, null, false];
            }
            if ($credit->state === 'IN_PROGRESS' && $attempt !== null && $attempt->status === FbrSubmissionStatus::Pending
                && $attempt->updated_at->isAfter(now()->subMinutes(2))) {
                return [null, null, false];
            }
            if ($credit->state === 'IN_PROGRESS' && ($attempt === null || $attempt->status !== FbrSubmissionStatus::Pending)) {
                throw new PlatformException('FBR_CREDIT_SUBMISSION_IN_PROGRESS', 'A different Credit Note submission is in progress.', 409);
            }
            if ($credit->state === 'REJECTED' && $attempt !== null) {
                throw new PlatformException('FBR_CREDIT_NEW_KEY_REQUIRED', 'Use a new idempotency key after correcting a rejected Credit Note.', 409);
            }
            $previousGeneration = $attempt?->claim_generation ?? 0;
            if ($previousGeneration < 0 || $previousGeneration >= PHP_INT_MAX) {
                throw new PlatformException('FBR_CREDIT_CLAIM_EXHAUSTED', 'Credit Note submission claim generation is exhausted.', 409);
            }
            $generation = $previousGeneration + 1;
            $attempt ??= PakistanFbrCreditNoteAttempt::query()->create([
                'company_id' => $companyId, 'credit_note_id' => $creditId, 'idempotency_key' => $key,
                'payload_hash' => $hash, 'claim_generation' => 0,
                'status' => FbrSubmissionStatus::Pending, 'submitted_by' => $user->id,
            ]);
            $attempt->update(['claim_generation' => $generation, 'status' => FbrSubmissionStatus::Pending,
                'error_message' => null, 'completed_at' => null]);
            $credit->update(['state' => 'IN_PROGRESS', 'submitted_at' => now()]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_credit_submission_started', 'pakistan_fbr', $credit,
                null, ['attempt_id' => $attempt->id, 'generation' => $generation]);

            return [$attempt->id, $generation, true];
        });

        if (! $claimed) {
            return PakistanFbrCreditNote::query()->where('company_id', $companyId)->with('lines')->findOrFail($creditId);
        }

        try {
            $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->with('lines')->findOrFail($creditId);
            $result = $this->gateway->submit($credit, 'pkfbr-credit:'.$creditId.':'.$key);
        } catch (FbrOutcomeUncertainException|FbrUnavailableException $exception) {
            $this->finish($companyId, $user, $creditId, $attemptId, $generation, FbrSubmissionStatus::Uncertain,
                null, [], 'Provider outcome requires external reconciliation.');
            throw $exception;
        }

        $status = $result->status === FbrSubmissionStatus::Accepted && filled($result->referenceNumber)
            ? FbrSubmissionStatus::Accepted
            : ($result->status === FbrSubmissionStatus::Rejected ? FbrSubmissionStatus::Rejected : FbrSubmissionStatus::Uncertain);
        $this->finish($companyId, $user, $creditId, $attemptId, $generation, $status, $result->referenceNumber,
            $this->sanitizer->sanitize($result->metadata) ?? [], $result->message);

        return PakistanFbrCreditNote::query()->where('company_id', $companyId)->with('lines')->findOrFail($creditId);
    }

    public function reconcile(string $companyId, User $user, string $creditId, string $outcome, string $evidenceNote, ?string $reference, string $key): PakistanFbrCreditNote
    {
        return DB::transaction(function () use ($companyId, $user, $creditId, $outcome, $evidenceNote, $reference, $key): PakistanFbrCreditNote {
            $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($creditId);
            $payloadHash = hash('sha256', json_encode([$creditId, $outcome, $evidenceNote, $reference], JSON_THROW_ON_ERROR));
            $previous = DB::table('pakistan_fbr_credit_note_reconciliations')->where('company_id', $companyId)
                ->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($previous !== null) {
                if ($previous->credit_note_id !== $creditId || ! hash_equals($previous->payload_hash, $payloadHash)) {
                    throw new PlatformException('FBR_CREDIT_RECONCILIATION_KEY_CONFLICT', 'The reconciliation key belongs to a different decision.', 409);
                }

                return $credit->load('lines');
            }
            if ($credit->state !== 'OUTCOME_UNCERTAIN') {
                throw new PlatformException('FBR_CREDIT_NOT_UNCERTAIN', 'Only an uncertain Credit Note may be reconciled.', 409);
            }
            $attempt = $credit->attempts()->where('company_id', $companyId)->latest()->lockForUpdate()->firstOrFail();
            $status = $outcome === 'ACCEPTED' ? FbrSubmissionStatus::Accepted : FbrSubmissionStatus::Rejected;
            if ($status === FbrSubmissionStatus::Accepted && blank($reference)) {
                throw new PlatformException('FBR_CREDIT_REFERENCE_REQUIRED', 'An authoritative FBR reference is required for acceptance.', 422);
            }
            $reference = $status === FbrSubmissionStatus::Accepted ? $reference : null;
            $attempt->update(['status' => $status, 'reference_number' => $reference,
                'response_metadata' => ['manual_reconciliation' => true], 'error_message' => null, 'completed_at' => now()]);
            $credit->update(['state' => $outcome, 'fbr_reference_number' => $reference,
                'fbr_response_metadata' => ['manual_reconciliation' => true], 'accepted_at' => $status === FbrSubmissionStatus::Accepted ? now() : null]);
            DB::table('pakistan_fbr_credit_note_reconciliations')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'credit_note_id' => $creditId,
                'idempotency_key' => $key, 'payload_hash' => $payloadHash,
                'previous_state' => 'OUTCOME_UNCERTAIN', 'resolved_state' => $outcome,
                'fbr_reference_number' => $reference, 'evidence_note' => $evidenceNote,
                'resolved_by' => $user->id, 'created_at' => now(),
            ]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_credit_reconciled', 'pakistan_fbr', $credit,
                ['state' => 'OUTCOME_UNCERTAIN'], ['state' => $outcome, 'reference' => $reference, 'evidence_note' => $evidenceNote]);

            return $credit->load('lines');
        });
    }

    /** @param array<string, mixed> $metadata */
    private function finish(string $companyId, User $user, string $creditId, string $attemptId, int $generation,
        FbrSubmissionStatus $status, ?string $reference, array $metadata, ?string $message): void
    {
        DB::transaction(function () use ($companyId, $user, $creditId, $attemptId, $generation, $status, $reference, $metadata, $message): void {
            $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($creditId);
            $attempt = PakistanFbrCreditNoteAttempt::query()->where('company_id', $companyId)->where('credit_note_id', $creditId)
                ->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->claim_generation !== $generation || $attempt->status !== FbrSubmissionStatus::Pending || $credit->state !== 'IN_PROGRESS') {
                return;
            }
            if ($reference !== null && mb_strlen($reference) > 255) {
                $status = FbrSubmissionStatus::Uncertain;
                $reference = null;
            }
            $state = match ($status) {
                FbrSubmissionStatus::Accepted => 'ACCEPTED',
                FbrSubmissionStatus::Rejected => 'REJECTED',
                default => 'OUTCOME_UNCERTAIN',
            };
            $attempt->update(['status' => $status, 'reference_number' => $reference, 'response_metadata' => $metadata,
                'error_message' => $this->sanitizer->sanitize(['message' => $message])['message'] ?? null,
                'completed_at' => now()]);
            $credit->update(['state' => $state, 'fbr_reference_number' => $reference, 'fbr_response_metadata' => $metadata,
                'accepted_at' => $state === 'ACCEPTED' ? now() : null]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_credit_submission_completed', 'pakistan_fbr', $credit,
                null, ['attempt_id' => $attemptId, 'status' => $state, 'reference' => $reference]);
        });
    }
}

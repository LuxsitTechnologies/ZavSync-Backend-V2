<?php

namespace App\Services\Fbr;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\FbrCompanyConfiguration;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Migration\HistoricalResponseSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PakistanFbrSubmissionService
{
    public function __construct(
        private readonly FbrGateway $gateway,
        private readonly PakistanFbrPayloadMapper $mapper,
        private readonly HistoricalResponseSanitizer $sanitizer,
        private readonly AuditService $audit,
    ) {}

    public function retry(string $companyId, User $user, PakistanFbrInvoice $invoice): PakistanFbrInvoice
    {
        $key = DB::transaction(function () use ($companyId, $invoice): string {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($document->is_historical || $document->fbr_reference_number !== null || in_array($document->fbr_status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted], true)) {
                throw ValidationException::withMessages(['invoice' => 'This FBR Invoice cannot be retried.']);
            }
            $attempt = $document->fbrAttempts()->where('company_id', $companyId)
                ->whereIn('status', [FbrSubmissionStatus::Pending, FbrSubmissionStatus::Failed])
                ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($attempt === null) {
                throw ValidationException::withMessages(['invoice' => 'No unresolved FBR submission exists to retry.']);
            }

            return $attempt->idempotency_key;
        });

        return $this->submit($companyId, $user, $invoice, $key);
    }

    public function submit(string $companyId, User $user, PakistanFbrInvoice $invoice, string $key): PakistanFbrInvoice
    {
        [$attempt, $payload, $context, $generation] = DB::transaction(function () use ($companyId, $user, $invoice, $key): array {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($invoice->id);
            if ($document->is_historical) {
                throw ValidationException::withMessages(['invoice' => 'Historical FBR Invoices are permanently blocked from submission, including ambiguous successes.']);
            }
            if ($document->fbr_reference_number !== null || in_array($document->fbr_status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted], true)) {
                return [null, null, null, null];
            }
            if (! config('services.fbr.pakistan_submission_enabled', false)) {
                throw new FbrUnavailableException('FBR Invoicing submission is disabled pending provider payload and reference-data certification.');
            }
            $configuration = FbrCompanyConfiguration::query()->where('company_id', $companyId)->first();
            $endpoint = $configuration === null ? null : config('services.fbr.endpoints.'.mb_strtolower($configuration->environment));
            if ($configuration === null || blank($configuration->credential) || ! is_string($endpoint) || $endpoint === '') {
                throw new FbrUnavailableException('FBR submission is not configured for this company.');
            }
            if ($document->lines->isEmpty() || $document->total <= 0) {
                throw ValidationException::withMessages(['invoice' => 'A positive calculated FBR Invoice is required.']);
            }
            if (blank($document->buyer_snapshot['registration_number'] ?? null)) {
                throw ValidationException::withMessages(['buyer_snapshot' => 'A verified buyer NTN/CNIC is required for this submission capability.']);
            }
            $payload = $this->mapper->map($document, $configuration);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $attempt = $document->fbrAttempts()->where('company_id', $companyId)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($attempt !== null && ! hash_equals($attempt->payload_hash, $hash)) {
                throw new ConflictHttpException('The idempotency key belongs to a different FBR Invoicing payload.');
            }
            $unresolved = $document->fbrAttempts()->whereIn('status', [FbrSubmissionStatus::Pending, FbrSubmissionStatus::Failed])->latest()->first();
            if ($unresolved !== null && $unresolved->idempotency_key !== $key) {
                throw new ConflictHttpException('Retry the unresolved submission with its original idempotency key.');
            }
            if ($attempt !== null && in_array($attempt->status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted, FbrSubmissionStatus::Rejected], true)) {
                return [null, null, null, null];
            }
            if ($attempt !== null && $attempt->status === FbrSubmissionStatus::Pending && $attempt->updated_at->isAfter(now()->subMinutes(2))) {
                return [null, null, null, null];
            }
            $previousGeneration = $attempt?->claim_generation ?? 0;
            if ($previousGeneration < 0 || $previousGeneration >= PHP_INT_MAX) {
                throw new ConflictHttpException('Submission claim generation is exhausted; administrative review is required.');
            }
            $generation = $previousGeneration + 1;
            $attempt ??= PakistanFbrSubmissionAttempt::query()->create([
                'company_id' => $companyId, 'invoice_id' => $document->id, 'idempotency_key' => $key,
                'payload_hash' => $hash, 'status' => FbrSubmissionStatus::Pending, 'submitted_by' => $user->id,
                'request_metadata' => ['invoice_number' => $document->invoice_number, 'total_minor' => $document->total, 'line_count' => $document->lines->count()],
            ]);
            $attempt->forceFill(['claim_generation' => $generation, 'status' => FbrSubmissionStatus::Pending, 'error_message' => null, 'completed_at' => null])->save();
            $document->update(['fbr_status' => FbrSubmissionStatus::Pending]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_started', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'payload_hash' => $hash]);

            return [$attempt, $payload, new FbrSubmissionContext($endpoint, $configuration->credential, $configuration->environment), $generation];
        });
        if ($payload === null) {
            return PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->findOrFail($invoice->id);
        }
        try {
            $result = $this->gateway->submit($payload, 'pkfbr:'.$invoice->id.':'.$key, $context);
        } catch (FbrUnavailableException) {
            DB::transaction(function () use ($companyId, $user, $invoice, $attempt, $generation): void {
                $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
                $attempt = $document->fbrAttempts()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id);
                if ($attempt->claim_generation !== $generation || $attempt->status !== FbrSubmissionStatus::Pending) {
                    return;
                }
                $attempt->update(['status' => FbrSubmissionStatus::Failed, 'error_message' => 'Provider unavailable; retry with the original idempotency key.', 'completed_at' => now()]);
                $document->update(['fbr_status' => FbrSubmissionStatus::Failed]);
                $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_failed', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'status' => 'failed']);
            });

            throw new FbrUnavailableException('FBR is unavailable. Retry with the original idempotency key.');
        }
        DB::transaction(function () use ($companyId, $user, $invoice, $attempt, $result, $generation): void {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            $attempt = $document->fbrAttempts()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->claim_generation !== $generation || $attempt->status !== FbrSubmissionStatus::Pending) {
                return;
            }
            if (is_string($result->referenceNumber) && mb_strlen($result->referenceNumber) > 255) {
                throw new FbrUnavailableException('Provider reference exceeds the supported length; reconciliation is required.');
            }
            $reference = is_string($result->referenceNumber) && trim($result->referenceNumber) !== '' ? $result->referenceNumber : null;
            $status = $result->status === FbrSubmissionStatus::Accepted && $reference === null ? FbrSubmissionStatus::Submitted : $result->status;
            $metadata = $this->sanitizer->sanitize($result->metadata) ?? [];
            $message = $this->sanitizer->sanitize(['message' => $result->message])['message'] ?? null;
            $attempt->update(['status' => $status, 'reference_number' => $reference, 'response_metadata' => $metadata, 'error_message' => $message, 'completed_at' => now()]);
            $document->update([
                'fbr_status' => $status, 'fbr_reference_number' => $reference, 'fbr_response_metadata' => $metadata,
                'document_state' => in_array($status, [FbrSubmissionStatus::Submitted, FbrSubmissionStatus::Accepted], true) ? 'ISSUED' : 'DRAFT',
            ]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_completed', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'status' => $status->value, 'reference_number' => $reference]);
        });

        return PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->findOrFail($invoice->id);
    }
}

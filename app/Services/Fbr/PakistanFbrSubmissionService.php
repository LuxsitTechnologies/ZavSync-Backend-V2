<?php

namespace App\Services\Fbr;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrOutcomeUncertainException;
use App\Exceptions\FbrUnavailableException;
use App\Exceptions\PlatformException;
use App\Models\CompanySetting;
use App\Models\FbrCompanyConfiguration;
use App\Models\PakistanFbrInvoice;
use App\Models\PakistanFbrSubmissionAttempt;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Migration\HistoricalResponseSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PakistanFbrSubmissionService
{
    public function __construct(
        private readonly FbrGateway $gateway,
        private readonly PakistanFbrPayloadMapper $mapper,
        private readonly HistoricalResponseSanitizer $sanitizer,
        private readonly AuditService $audit,
        private readonly FbrInvoicePrintService $printing,
    ) {}

    public function retry(string $companyId, User $user, PakistanFbrInvoice $invoice): PakistanFbrInvoice
    {
        $key = DB::transaction(function () use ($companyId, $invoice): string {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($document->is_historical || $document->document_state === 'DELETED' || $document->fbr_reference_number !== null || in_array($document->fbr_status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted, FbrSubmissionStatus::Uncertain], true)) {
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

    public function reconcile(string $companyId, User $user, PakistanFbrInvoice $invoice): PakistanFbrInvoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice): PakistanFbrInvoice {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($document->is_historical || $document->document_state === 'DELETED') {
                throw ValidationException::withMessages(['invoice' => 'This FBR Invoice cannot be reconciled.']);
            }
            if ($document->fbr_status !== FbrSubmissionStatus::Pending) {
                return $document->load('lines');
            }
            $attempt = $document->fbrAttempts()->where('company_id', $companyId)->where('status', FbrSubmissionStatus::Pending)
                ->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($attempt === null || $attempt->updated_at->isAfter(now()->subMinutes(2))) {
                return $document->load('lines');
            }
            $attempt->update(['status' => FbrSubmissionStatus::Uncertain, 'error_message' => 'Provider outcome requires external reconciliation.', 'completed_at' => now()]);
            $document->update(['fbr_status' => FbrSubmissionStatus::Uncertain]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_uncertain', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'status' => 'uncertain']);

            return $document->load('lines');
        });
    }

    public function resolveUncertain(string $companyId, User $user, PakistanFbrInvoice $invoice,
        string $outcome, string $evidenceNote, ?string $reference, string $key): PakistanFbrInvoice
    {
        return DB::transaction(function () use ($companyId, $user, $invoice, $outcome, $evidenceNote, $reference, $key): PakistanFbrInvoice {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
            if ($document->is_historical || $document->document_state === 'DELETED') {
                throw new PlatformException('FBR_RECONCILIATION_NOT_ALLOWED', 'This FBR Invoice cannot be reconciled.', 409);
            }
            $hash = hash('sha256', json_encode([$document->id, $outcome, $evidenceNote, $reference], JSON_THROW_ON_ERROR));
            $existing = DB::table('pakistan_fbr_invoice_reconciliations')->where('company_id', $companyId)
                ->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if ($existing->invoice_id !== $document->id || ! hash_equals($existing->payload_hash, $hash)) {
                    throw new PlatformException('FBR_RECONCILIATION_IDEMPOTENCY_CONFLICT', 'The idempotency key belongs to a different reconciliation.', 409);
                }

                return $document->load('lines');
            }
            if ($document->fbr_status !== FbrSubmissionStatus::Uncertain) {
                throw new PlatformException('FBR_OUTCOME_NOT_UNCERTAIN', 'Only an uncertain FBR Invoice may be manually reconciled.', 409);
            }
            $attempt = $document->fbrAttempts()->where('company_id', $companyId)
                ->where('status', FbrSubmissionStatus::Uncertain)->latest()->lockForUpdate()->firstOrFail();
            $status = $outcome === 'ACCEPTED' ? FbrSubmissionStatus::Accepted : FbrSubmissionStatus::Rejected;
            if ($status === FbrSubmissionStatus::Accepted && blank($reference)) {
                throw new PlatformException('FBR_REFERENCE_REQUIRED', 'An authoritative FBR reference is required for acceptance.', 422);
            }
            $reference = $status === FbrSubmissionStatus::Accepted ? $reference : null;
            $attempt->update(['status' => $status, 'reference_number' => $reference,
                'response_metadata' => ['manual_reconciliation' => true], 'error_message' => null, 'completed_at' => now()]);
            $document->update(['fbr_status' => $status, 'fbr_reference_number' => $reference,
                'fbr_response_metadata' => ['manual_reconciliation' => true],
                'document_state' => $status === FbrSubmissionStatus::Accepted ? 'ISSUED' : 'DRAFT']);
            if ($status === FbrSubmissionStatus::Accepted && $document->print_snapshot === null) {
                $document->update(['print_snapshot' => $this->printing->build($document, captureCurrentSettings: true)]);
            }
            DB::table('pakistan_fbr_invoice_reconciliations')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'invoice_id' => $document->id,
                'attempt_id' => $attempt->id, 'idempotency_key' => $key, 'payload_hash' => $hash,
                'previous_status' => FbrSubmissionStatus::Uncertain->value, 'resolved_status' => $status->value,
                'fbr_reference_number' => $reference, 'evidence_note' => $evidenceNote,
                'resolved_by' => $user->id, 'created_at' => now(),
            ]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_invoice_reconciled', 'pakistan_fbr', $document,
                ['fbr_status' => FbrSubmissionStatus::Uncertain->value],
                ['fbr_status' => $status->value, 'reference' => $reference, 'evidence_note' => $evidenceNote]);

            return $document->load('lines');
        });
    }

    public function submit(string $companyId, User $user, PakistanFbrInvoice $invoice, string $key): PakistanFbrInvoice
    {
        [$attempt, $payload, $context, $generation] = DB::transaction(function () use ($companyId, $user, $invoice, $key): array {
            $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->lockForUpdate()->findOrFail($invoice->id);
            if ($document->document_state === 'DELETED') {
                throw ValidationException::withMessages(['invoice' => 'Deleted FBR Invoice drafts cannot be submitted.']);
            }
            if ($document->is_historical) {
                throw ValidationException::withMessages(['invoice' => 'Historical FBR Invoices are permanently blocked from submission, including ambiguous successes.']);
            }
            if ($document->fbr_status === FbrSubmissionStatus::Uncertain) {
                throw new ConflictHttpException('The FBR outcome is uncertain; external reconciliation is required before another submission.');
            }
            if ($document->fbr_reference_number !== null || in_array($document->fbr_status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted], true)) {
                return [null, null, null, null];
            }
            if (! config('services.fbr.pakistan_submission_enabled', false)) {
                throw new FbrUnavailableException('FBR Invoice submission is disabled pending provider payload and reference-data certification.');
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
                throw ValidationException::withMessages(['buyer_snapshot' => 'A valid buyer NTN/CNIC is required for this submission capability.']);
            }
            $payload = $this->mapper->map($document, $configuration);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $attempt = $document->fbrAttempts()->where('company_id', $companyId)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($attempt !== null && ! hash_equals($attempt->payload_hash, $hash)) {
                throw new ConflictHttpException('The idempotency key belongs to a different FBR Invoice payload.');
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
            $settings = CompanySetting::query()->where('company_id', $companyId)->first();
            $sellerSnapshot = [
                'tax_identifier' => $payload['sellerNTNCNIC'],
                'business_name' => $payload['sellerBusinessName'],
                'province' => $payload['sellerProvince'],
                'address' => $payload['sellerAddress'],
                'email' => $settings?->email,
                'phone' => $settings?->phone,
            ];
            $document->update([
                'fbr_status' => FbrSubmissionStatus::Pending,
                'seller_snapshot' => $document->fbr_status === FbrSubmissionStatus::Rejected
                    ? $sellerSnapshot : ($document->seller_snapshot ?? $sellerSnapshot),
            ]);
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_started', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'payload_hash' => $hash]);

            return [$attempt, $payload, new FbrSubmissionContext($endpoint, $configuration->credential, $configuration->environment), $generation];
        });
        if ($payload === null) {
            return PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->findOrFail($invoice->id);
        }
        try {
            $result = $this->gateway->submit($payload, 'pkfbr:'.$invoice->id.':'.$key, $context);
        } catch (FbrOutcomeUncertainException) {
            DB::transaction(function () use ($companyId, $user, $invoice, $attempt, $generation): void {
                $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id);
                $attempt = $document->fbrAttempts()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id);
                if ($attempt->claim_generation !== $generation || $attempt->status !== FbrSubmissionStatus::Pending) {
                    return;
                }
                $attempt->update(['status' => FbrSubmissionStatus::Uncertain, 'error_message' => 'Provider outcome requires external reconciliation.', 'completed_at' => now()]);
                $document->update(['fbr_status' => FbrSubmissionStatus::Uncertain]);
                $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_uncertain', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'status' => 'uncertain']);
            });

            throw new FbrOutcomeUncertainException('The FBR outcome is uncertain. Reconciliation is required before another submission.');
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
            if (in_array($status, [FbrSubmissionStatus::Submitted, FbrSubmissionStatus::Accepted], true)
                && $document->print_snapshot === null) {
                $document->update(['print_snapshot' => $this->printing->build($document, captureCurrentSettings: true)]);
            }
            $this->audit->recordOperation($user, $companyId, 'pakistan_fbr_submission_completed', 'pakistan_fbr', $document, null, ['attempt_id' => $attempt->id, 'status' => $status->value, 'reference_number' => $reference]);
        });

        return PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->findOrFail($invoice->id);
    }
}

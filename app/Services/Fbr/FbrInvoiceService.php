<?php

namespace App\Services\Fbr;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\FbrCompanyConfiguration;
use App\Models\FbrSubmissionAttempt;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Migration\HistoricalResponseSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FbrInvoiceService
{
    public function __construct(private readonly FbrGateway $gateway, private readonly HistoricalResponseSanitizer $responseSanitizer) {}

    public function submit(string $companyId, User $user, Invoice $invoice, string $idempotencyKey): Invoice
    {
        [$attempt, $payload, $context] = DB::transaction(function () use ($companyId, $user, $invoice, $idempotencyKey): array {
            $invoice = Invoice::query()->where('company_id', $companyId)->with(['customer', 'lines'])->lockForUpdate()->findOrFail($invoice->id);
            $this->validateForSubmission($invoice);
            if ($invoice->fbr_reference_number !== null || $invoice->fbr_status === FbrSubmissionStatus::Accepted) {
                return [null, null, null];
            }
            $configuration = FbrCompanyConfiguration::query()->where('company_id', $companyId)->first();
            if ($configuration === null || blank($configuration->credential)) {
                throw new FbrUnavailableException('FBR submission is not configured for this company.');
            }
            $endpoint = config('services.fbr.endpoints.'.mb_strtolower($configuration->environment));
            if (! is_string($endpoint) || $endpoint === '') {
                throw new FbrUnavailableException('FBR submission is not configured for this environment.');
            }
            $payload = $this->payload($invoice);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $attempt = FbrSubmissionAttempt::query()->where('invoice_id', $invoice->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($attempt !== null && ! hash_equals($attempt->payload_hash, $hash)) {
                throw new ConflictHttpException('The idempotency key has already been used for a different FBR payload.');
            }
            $unresolved = FbrSubmissionAttempt::query()->where('invoice_id', $invoice->id)
                ->whereIn('status', [FbrSubmissionStatus::Pending, FbrSubmissionStatus::Failed])->latest()->first();
            if ($unresolved !== null && $unresolved->idempotency_key !== $idempotencyKey) {
                throw new ConflictHttpException('Retry the unresolved FBR submission with its original idempotency key.');
            }
            if ($attempt !== null && in_array($attempt->status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted, FbrSubmissionStatus::Rejected], true)) {
                return [$attempt, null, null];
            }
            if ($attempt !== null && $attempt->status === FbrSubmissionStatus::Pending && $attempt->updated_at->isAfter(now()->subMinutes(2))) {
                return [$attempt, null, null];
            }
            $attempt ??= FbrSubmissionAttempt::query()->create([
                'company_id' => $companyId, 'invoice_id' => $invoice->id, 'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash, 'status' => FbrSubmissionStatus::Pending,
                'request_metadata' => ['invoice_number' => $invoice->invoice_number, 'line_count' => $invoice->lines->count(), 'total' => $invoice->total],
                'submitted_by' => $user->id,
            ]);
            $attempt->update(['status' => FbrSubmissionStatus::Pending, 'error_message' => null]);
            $invoice->update(['fbr_status' => FbrSubmissionStatus::Pending]);

            return [$attempt, $payload, new FbrSubmissionContext($endpoint, $configuration->credential, $configuration->environment)];
        });
        if ($payload === null) {
            return Invoice::query()->where('company_id', $companyId)->with(['customer', 'lines', 'journal'])->findOrFail($invoice->id);
        }
        try {
            $result = $this->gateway->submit($payload, 'accounting:'.$invoice->id.':'.$idempotencyKey, $context);
        } catch (FbrUnavailableException $exception) {
            $safeMessage = $this->responseSanitizer->sanitize(['message' => $exception->getMessage()])['message'] ?? 'FBR is unavailable.';
            DB::transaction(function () use ($attempt, $companyId, $invoice, $safeMessage): void {
                FbrSubmissionAttempt::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id)->update(['status' => FbrSubmissionStatus::Failed, 'error_message' => $safeMessage, 'completed_at' => now()]);
                Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id)->update(['fbr_status' => FbrSubmissionStatus::Failed]);
            });

            throw new FbrUnavailableException($safeMessage);
        }
        DB::transaction(function () use ($attempt, $companyId, $invoice, $result): void {
            $metadata = $this->responseSanitizer->sanitize($result->metadata) ?? [];
            $message = $this->responseSanitizer->sanitize(['message' => $result->message])['message'] ?? null;
            FbrSubmissionAttempt::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id)->update([
                'status' => $result->status, 'response_metadata' => $metadata, 'reference_number' => $result->referenceNumber,
                'error_message' => is_string($message) ? $message : null, 'completed_at' => now(),
            ]);
            Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id)->update([
                'fbr_status' => $result->status, 'fbr_reference_number' => $result->referenceNumber, 'fbr_response_metadata' => $metadata,
            ]);
        });

        return Invoice::query()->where('company_id', $companyId)->with(['customer', 'lines', 'journal'])->findOrFail($invoice->id);
    }

    private function validateForSubmission(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages(['invoice' => 'A void invoice cannot be submitted to FBR.']);
        }
        if ($invoice->customer->ntn === null && $invoice->customer->cnic === null) {
            throw ValidationException::withMessages(['customer' => 'The customer must have a valid NTN or CNIC before FBR submission.']);
        }
        if ($invoice->lines->isEmpty() || $invoice->total <= 0) {
            throw ValidationException::withMessages(['invoice' => 'The invoice must contain a positive calculated line before FBR submission.']);
        }
    }

    /** @return array<string, mixed> */
    private function payload(Invoice $invoice): array
    {
        return [
            'invoiceNumber' => $invoice->invoice_number, 'invoiceDate' => $invoice->invoice_date->format('Y-m-d'),
            'buyer' => ['name' => $invoice->customer->legal_name ?: $invoice->customer->name, 'ntn' => $invoice->customer->ntn, 'cnic' => $invoice->customer->cnic, 'strn' => $invoice->customer->strn, 'province' => $invoice->customer->province],
            'currency' => $invoice->currency, 'subtotalMinor' => $invoice->subtotal, 'discountMinor' => $invoice->discount,
            'salesTaxMinor' => $invoice->sales_tax, 'otherTaxMinor' => $invoice->other_tax, 'advanceTaxMinor' => $invoice->advance_tax,
            'withholdingTaxMinor' => $invoice->withholding_tax, 'totalMinor' => $invoice->total,
            'lines' => $invoice->lines->map(fn ($line): array => [
                'description' => $line->description, 'quantityMilli' => $line->quantity_milli, 'unit' => $line->unit,
                'unitPriceMinor' => $line->unit_price, 'discountMinor' => $line->discount, 'taxRateBps' => $line->tax_rate_bps,
                'taxMinor' => $line->tax_amount, 'totalMinor' => $line->total, 'salesType' => $line->sales_type,
                'taxMetadata' => $line->tax_metadata,
            ])->all(),
        ];
    }
}

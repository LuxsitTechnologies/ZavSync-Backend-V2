<?php

namespace App\Services\Fbr;

use App\Contracts\FbrGateway;
use App\Enums\FbrSubmissionStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\FbrUnavailableException;
use App\Models\FbrSubmissionAttempt;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FbrInvoiceService
{
    public function __construct(private readonly FbrGateway $gateway) {}

    public function submit(string $companyId, User $user, Invoice $invoice, string $idempotencyKey): Invoice
    {
        [$attempt, $payload] = DB::transaction(function () use ($companyId, $user, $invoice, $idempotencyKey): array {
            $invoice = Invoice::query()->where('company_id', $companyId)->with(['customer', 'lines'])->lockForUpdate()->findOrFail($invoice->id);
            $this->validateForSubmission($invoice);
            $payload = $this->payload($invoice);
            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $attempt = FbrSubmissionAttempt::query()->where('invoice_id', $invoice->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($attempt !== null && ! hash_equals($attempt->payload_hash, $hash)) {
                throw new ConflictHttpException('The idempotency key has already been used for a different FBR payload.');
            }
            if ($attempt !== null && in_array($attempt->status, [FbrSubmissionStatus::Accepted, FbrSubmissionStatus::Submitted, FbrSubmissionStatus::Rejected], true)) {
                return [$attempt, null];
            }
            if ($attempt !== null && $attempt->status === FbrSubmissionStatus::Pending && $attempt->updated_at->isAfter(now()->subMinutes(2))) {
                return [$attempt, null];
            }
            $attempt ??= FbrSubmissionAttempt::query()->create([
                'company_id' => $companyId, 'invoice_id' => $invoice->id, 'idempotency_key' => $idempotencyKey,
                'payload_hash' => $hash, 'status' => FbrSubmissionStatus::Pending,
                'request_metadata' => ['invoice_number' => $invoice->invoice_number, 'line_count' => $invoice->lines->count(), 'total' => $invoice->total],
                'submitted_by' => $user->id,
            ]);
            $attempt->update(['status' => FbrSubmissionStatus::Pending, 'error_message' => null]);
            $invoice->update(['fbr_status' => FbrSubmissionStatus::Pending]);

            return [$attempt, $payload];
        });
        if ($payload === null) {
            return Invoice::query()->where('company_id', $companyId)->with(['customer', 'lines', 'journal'])->findOrFail($invoice->id);
        }
        try {
            $result = $this->gateway->submit($payload, $idempotencyKey);
        } catch (FbrUnavailableException $exception) {
            DB::transaction(function () use ($attempt, $companyId, $invoice, $exception): void {
                FbrSubmissionAttempt::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id)->update(['status' => FbrSubmissionStatus::Failed, 'error_message' => $exception->getMessage(), 'completed_at' => now()]);
                Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id)->update(['fbr_status' => FbrSubmissionStatus::Failed]);
            });

            throw $exception;
        }
        DB::transaction(function () use ($attempt, $companyId, $invoice, $result): void {
            FbrSubmissionAttempt::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($attempt->id)->update([
                'status' => $result->status, 'response_metadata' => $result->metadata, 'reference_number' => $result->referenceNumber,
                'error_message' => $result->message, 'completed_at' => now(),
            ]);
            Invoice::query()->where('company_id', $companyId)->lockForUpdate()->findOrFail($invoice->id)->update([
                'fbr_status' => $result->status, 'fbr_reference_number' => $result->referenceNumber, 'fbr_response_metadata' => $result->metadata,
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

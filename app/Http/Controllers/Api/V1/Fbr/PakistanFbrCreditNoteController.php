<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Exceptions\FbrUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Fbr\StorePakistanFbrCreditNoteRequest;
use App\Models\Company;
use App\Models\PakistanFbrCreditNote;
use App\Models\PakistanFbrInvoice;
use App\Services\Fbr\PakistanFbrCreditNoteService;
use App\Services\Fbr\PakistanFbrCreditNoteSubmissionService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PakistanFbrCreditNoteController extends Controller
{
    public function __construct(private readonly PakistanFbrCreditNoteService $credits,
        private readonly PakistanFbrCreditNoteSubmissionService $submissions,
        private readonly PlatformAccessService $access) {}

    public function index(Request $request, string $invoice): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')
            ->with(['lines', 'creditNotes.lines'])->findOrFail($invoice);

        return response()->json([
            'invoice_id' => $document->id,
            'eligible' => $document->canCreateInternalCreditNote(),
            'full_reversal_total' => $document->canCreateInternalCreditNote() ? $document->total : null,
            'data' => $document->creditNotes->map(fn (PakistanFbrCreditNote $credit): array => $this->payload($credit)),
        ]);
    }

    public function store(StorePakistanFbrCreditNoteRequest $request, string $invoice): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');
        $document = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }
        $credit = $this->credits->create($companyId, $request->user(), $document, $request->validated(), $key);

        return response()->json($this->payload($credit), $credit->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $credit = PakistanFbrCreditNote::query()->where('company_id', $companyId)->where('invoice_id', $invoice)
            ->with('lines')->findOrFail($creditNote);

        return response()->json($this->payload($credit));
    }

    public function printData(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $credit = $this->scopedCredit($companyId, $invoice, $creditNote);

        return response()->json([
            'document_type' => 'FBR_CREDIT_NOTE', 'snapshot_version' => 1,
            'watermark' => $credit->state === 'DRAFT' ? 'DRAFT / NOT SUBMITTED TO FBR' : null,
            ...$this->payload($credit),
            'verification' => ['fbr_reference_number' => $credit->fbr_reference_number, 'qr_content' => null],
        ]);
    }

    public function update(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $credit = $this->credits->updateReason($companyId, $request->user(), $invoice, $creditNote, $data['reason']);

        return response()->json($this->payload($credit));
    }

    public function destroy(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');
        $this->credits->deleteDraft($companyId, $request->user(), $invoice, $creditNote);

        return response()->json(['deleted' => true]);
    }

    public function submit(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.submit');
        $this->scopedCredit($companyId, $invoice, $creditNote);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }
        try {
            $credit = $this->submissions->submit($companyId, $request->user(), $creditNote, $key);
        } catch (FbrUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'error_code' => 'FBR_UNAVAILABLE'], 503);
        }

        return response()->json($this->payload($credit));
    }

    public function reconcile(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = (string) $request->attributes->get('company_id');
        $company = Company::query()->findOrFail($companyId);
        if ($company->created_by !== $request->user()->id) {
            $this->access->authorize($request->user(), $companyId, 'fbr.reconcile');
        }
        $this->scopedCredit($companyId, $invoice, $creditNote);
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }
        $data = $request->validate([
            'outcome' => ['required', 'in:ACCEPTED,REJECTED'],
            'evidence_note' => ['required', 'string', 'min:10', 'max:2000'],
            'fbr_reference_number' => ['required_if:outcome,ACCEPTED', 'nullable', 'string', 'max:255'],
        ]);
        $credit = $this->submissions->reconcile($companyId, $request->user(), $creditNote, $data['outcome'],
            $data['evidence_note'], $data['fbr_reference_number'] ?? null, $key);

        return response()->json($this->payload($credit));
    }

    public function attempts(Request $request, string $invoice, string $creditNote): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $credit = $this->scopedCredit($companyId, $invoice, $creditNote);

        return response()->json(['data' => $credit->attempts()->orderBy('created_at')->get()->map(fn ($attempt): array => [
            'id' => $attempt->id, 'status' => $attempt->status->value,
            'reference_number' => $attempt->reference_number, 'response_metadata' => $attempt->response_metadata,
            'error_message' => $attempt->error_message, 'completed_at' => $attempt->completed_at?->toISOString(),
        ])]);
    }

    private function scopedCredit(string $companyId, string $invoiceId, string $creditId): PakistanFbrCreditNote
    {
        return PakistanFbrCreditNote::query()->where('company_id', $companyId)->where('invoice_id', $invoiceId)
            ->with('lines')->findOrFail($creditId);
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    /** @return array<string, mixed> */
    private function payload(PakistanFbrCreditNote $credit): array
    {
        return [
            'id' => $credit->id, 'company_id' => $credit->company_id, 'invoice_id' => $credit->invoice_id,
            'credit_note_number' => $credit->credit_note_number, 'state' => $credit->state,
            'fbr_reference_number' => $credit->fbr_reference_number,
            'fbr_response_metadata' => $credit->fbr_response_metadata,
            'fully_credited' => $credit->state === 'ACCEPTED',
            'capabilities' => [
                'edit_reason' => in_array($credit->state, ['DRAFT', 'REJECTED'], true),
                'delete' => $credit->state === 'DRAFT' && ! $credit->attempts()->exists(),
                'submit' => in_array($credit->state, ['DRAFT', 'REJECTED'], true),
                'reconcile' => $credit->state === 'OUTCOME_UNCERTAIN',
            ],
            'provider_submission' => 'NOT_CERTIFIED', 'accounting_integration' => 'NOT_INTEGRATED',
            'issued_on' => $credit->issued_on->format('Y-m-d'), 'reason' => $credit->reason,
            'original_invoice_number' => $credit->original_invoice_number,
            'original_fbr_reference_number' => $credit->original_fbr_reference_number,
            'original_fbr_evidence' => $credit->original_fbr_evidence,
            'seller_snapshot' => $credit->seller_snapshot,
            'buyer_snapshot' => $credit->buyer_snapshot,
            'portal_snapshot' => $credit->portal_snapshot,
            'taxable_amount' => $credit->taxable_amount, 'sales_tax' => $credit->sales_tax,
            'extra_tax' => $credit->extra_tax, 'further_tax' => $credit->further_tax,
            'st_withheld' => $credit->st_withheld, 'total' => $credit->total,
            'lines' => $credit->lines->map(fn ($line): array => $line->only([
                'id', 'invoice_line_id', 'source_snapshot', 'taxable_amount', 'sales_tax', 'extra_tax', 'further_tax', 'st_withheld', 'total',
            ])),
            'created_at' => $credit->created_at?->toISOString(),
        ];
    }
}

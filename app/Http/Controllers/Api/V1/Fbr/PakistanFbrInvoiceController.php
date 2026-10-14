<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Enums\FbrSubmissionStatus;
use App\Exceptions\FbrUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Fbr\SavePakistanFbrInvoiceRequest;
use App\Http\Resources\PakistanFbrInvoiceResource;
use App\Models\Company;
use App\Models\PakistanFbrInvoice;
use App\Services\Fbr\FbrInvoicePrintService;
use App\Services\Fbr\PakistanFbrInvoiceService;
use App\Services\Fbr\PakistanFbrSubmissionService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PakistanFbrInvoiceController extends Controller
{
    public function __construct(
        private readonly PakistanFbrInvoiceService $invoices,
        private readonly PakistanFbrSubmissionService $submissions,
        private readonly PlatformAccessService $access,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $data = $request->validate([
            'historical' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:120'],
            'fbr_status' => ['nullable', Rule::enum(FbrSubmissionStatus::class)],
            'document_state' => ['nullable', Rule::in(['DRAFT', 'ISSUED'])],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'sort_by' => ['nullable', Rule::in(['invoice_date', 'created_at', 'invoice_number', 'total', 'fbr_status'])],
            'sort_order' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $query = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->withExists('fbrAttempts')->with('creditNotes');
        if (isset($data['historical'])) {
            $query->where('is_historical', $data['historical']);
        }
        if (isset($data['search']) && trim($data['search']) !== '') {
            $search = '%'.trim($data['search']).'%';
            $query->where(fn (Builder $builder): Builder => $builder
                ->where('invoice_number', 'like', $search)
                ->orWhere('buyer_snapshot->name', 'like', $search));
        }
        if (isset($data['fbr_status'])) {
            $query->where('fbr_status', $data['fbr_status']);
        }
        if (isset($data['document_state'])) {
            $query->where('document_state', $data['document_state']);
        }
        if (isset($data['date_from'])) {
            $query->whereDate('invoice_date', '>=', $data['date_from']);
        }
        if (isset($data['date_to'])) {
            $query->whereDate('invoice_date', '<=', $data['date_to']);
        }

        $sortBy = $data['sort_by'] ?? 'invoice_date';
        $sortOrder = $data['sort_order'] ?? 'desc';

        return PakistanFbrInvoiceResource::collection($query->orderBy($sortBy, $sortOrder)->orderBy('id')->paginate($data['per_page'] ?? 25));
    }

    public function show(Request $request, string $invoice): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');

        return new PakistanFbrInvoiceResource(PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->with(['lines', 'creditNotes'])->withExists('fbrAttempts')->findOrFail($invoice));
    }

    public function printData(Request $request, string $invoice, FbrInvoicePrintService $printing): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $document = PakistanFbrInvoice::query()->where('company_id', $companyId)
            ->where('document_state', '!=', 'DELETED')->with('lines')->findOrFail($invoice);

        return response()->json($printing->data($document));
    }

    public function store(SavePakistanFbrInvoiceRequest $request): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');

        return new PakistanFbrInvoiceResource($this->invoices->create($companyId, $request->user(), $request->validated(), $this->key($request)));
    }

    public function update(SavePakistanFbrInvoiceRequest $request, string $invoice): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);

        return new PakistanFbrInvoiceResource($this->invoices->update($companyId, $request->user(), $model, $request->validated()));
    }

    public function destroy(Request $request, string $invoice): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);
        $this->invoices->deleteDraft($companyId, $request->user(), $model);

        return response()->json(['message' => 'FBR Invoice draft deleted.']);
    }

    public function submit(Request $request, string $invoice): PakistanFbrInvoiceResource|JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.submit');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);
        try {
            return new PakistanFbrInvoiceResource($this->submissions->submit($companyId, $request->user(), $model, $this->key($request)));
        } catch (FbrUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'error_code' => 'FBR_UNAVAILABLE', 'errors' => ['fbr' => [$exception->getMessage()]]], 503);
        }
    }

    public function retry(Request $request, string $invoice): PakistanFbrInvoiceResource|JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.submit');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);
        try {
            return new PakistanFbrInvoiceResource($this->submissions->retry($companyId, $request->user(), $model));
        } catch (FbrUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'error_code' => 'FBR_UNAVAILABLE', 'errors' => ['fbr' => [$exception->getMessage()]]], 503);
        }
    }

    public function reconcile(Request $request, string $invoice): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.submit');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);

        return new PakistanFbrInvoiceResource($this->submissions->reconcile($companyId, $request->user(), $model));
    }

    public function resolve(Request $request, string $invoice): PakistanFbrInvoiceResource
    {
        $companyId = (string) $request->attributes->get('company_id');
        $company = Company::query()->findOrFail($companyId);
        if ($company->created_by !== $request->user()->id) {
            $this->access->authorize($request->user(), $companyId, 'fbr.reconcile');
        }
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)
            ->where('document_state', '!=', 'DELETED')->findOrFail($invoice);
        $data = $request->validate([
            'outcome' => ['required', Rule::in(['ACCEPTED', 'REJECTED'])],
            'evidence_note' => ['required', 'string', 'min:10', 'max:2000'],
            'fbr_reference_number' => ['required_if:outcome,ACCEPTED', 'nullable', 'string', 'max:255'],
        ]);

        return new PakistanFbrInvoiceResource($this->submissions->resolveUncertain($companyId, $request->user(), $model,
            $data['outcome'], $data['evidence_note'], $data['fbr_reference_number'] ?? null, $this->key($request)));
    }

    public function attempts(Request $request, string $invoice): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->where('document_state', '!=', 'DELETED')->findOrFail($invoice);

        return response()->json($model->fbrAttempts()->latest()->get()->map(fn ($attempt): array => [
            'id' => $attempt->id, 'status' => $attempt->status->value, 'payload_hash' => $attempt->payload_hash,
            'reference_number' => $attempt->reference_number, 'response_metadata' => $attempt->response_metadata,
            'error_message' => $attempt->error_message, 'created_at' => $attempt->created_at?->toISOString(), 'completed_at' => $attempt->completed_at?->toISOString(),
        ]));
    }

    private function authorize(Request $request, string $permission): string
    {
        $companyId = (string) $request->attributes->get('company_id');
        $this->access->authorize($request->user(), $companyId, $permission);

        return $companyId;
    }

    private function key(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }

        return $key;
    }
}

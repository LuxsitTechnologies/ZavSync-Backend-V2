<?php

namespace App\Http\Controllers\Api\V1\Fbr;

use App\Exceptions\FbrUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Fbr\SavePakistanFbrInvoiceRequest;
use App\Http\Resources\PakistanFbrInvoiceResource;
use App\Models\PakistanFbrInvoice;
use App\Services\Fbr\PakistanFbrInvoiceService;
use App\Services\Fbr\PakistanFbrSubmissionService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        $data = $request->validate(['historical' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $query = PakistanFbrInvoice::query()->where('company_id', $companyId);
        if (isset($data['historical'])) {
            $query->where('is_historical', $data['historical']);
        }

        return PakistanFbrInvoiceResource::collection($query->orderByDesc('invoice_date')->orderBy('id')->paginate($data['per_page'] ?? 25));
    }

    public function show(Request $request, string $invoice): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');

        return new PakistanFbrInvoiceResource(PakistanFbrInvoice::query()->where('company_id', $companyId)->with('lines')->findOrFail($invoice));
    }

    public function store(SavePakistanFbrInvoiceRequest $request): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');

        return new PakistanFbrInvoiceResource($this->invoices->create($companyId, $request->user(), $request->validated(), $this->key($request)));
    }

    public function update(SavePakistanFbrInvoiceRequest $request, string $invoice): PakistanFbrInvoiceResource
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.manage');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->findOrFail($invoice);

        return new PakistanFbrInvoiceResource($this->invoices->update($companyId, $request->user(), $model, $request->validated()));
    }

    public function submit(Request $request, string $invoice): PakistanFbrInvoiceResource|JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.submit');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->findOrFail($invoice);
        try {
            return new PakistanFbrInvoiceResource($this->submissions->submit($companyId, $request->user(), $model, $this->key($request)));
        } catch (FbrUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'error_code' => 'FBR_UNAVAILABLE', 'errors' => ['fbr' => [$exception->getMessage()]]], 503);
        }
    }

    public function retry(Request $request, string $invoice): PakistanFbrInvoiceResource|JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.submit');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->findOrFail($invoice);
        try {
            return new PakistanFbrInvoiceResource($this->submissions->retry($companyId, $request->user(), $model));
        } catch (FbrUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'error_code' => 'FBR_UNAVAILABLE', 'errors' => ['fbr' => [$exception->getMessage()]]], 503);
        }
    }

    public function attempts(Request $request, string $invoice): JsonResponse
    {
        $companyId = $this->authorize($request, 'pakistan_fbr.view');
        $model = PakistanFbrInvoice::query()->where('company_id', $companyId)->findOrFail($invoice);

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

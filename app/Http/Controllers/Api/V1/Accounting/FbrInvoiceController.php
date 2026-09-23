<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Exceptions\FbrUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\AuditService;
use App\Services\Fbr\FbrInvoiceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class FbrInvoiceController extends Controller
{
    public function __construct(private readonly FbrInvoiceService $fbrService, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);
        $query = Invoice::query()->where('company_id', $this->companyId($request))->with('customer');
        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(fn (Builder $builder) => $builder->where('invoice_number', 'like', $search)->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $search)));
        }
        if ($request->filled('status') && $request->string('status')->toString() !== 'all') {
            $query->where('fbr_status', $request->string('status')->toString());
        }

        return InvoiceResource::collection($query->orderByDesc('invoice_date')->get());
    }

    public function submit(Request $request, string $invoice): InvoiceResource|JsonResponse
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.post'), 403);
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Idempotency-Key header is required.']);
        }
        $model = Invoice::query()->where('company_id', $companyId)->findOrFail($invoice);
        $old = $model->toArray();
        $this->auditService->record($request, $request->user(), $companyId, 'submission_attempt', 'fbr', $model, null, ['invoice_number' => $model->invoice_number, 'idempotency_key' => $idempotencyKey]);
        try {
            $submitted = $this->fbrService->submit($companyId, $request->user(), $model, $idempotencyKey);
        } catch (FbrUnavailableException $exception) {
            $failed = $model->fresh();
            $this->auditService->record($request, $request->user(), $companyId, 'submission_failed', 'fbr', $failed, $old, ['fbr_status' => $failed->fbr_status->value, 'message' => $exception->getMessage()]);

            return response()->json(['message' => $exception->getMessage(), 'errors' => ['fbr' => [$exception->getMessage()]]], 503);
        }
        $action = match ($submitted->fbr_status->value) {
            'rejected' => 'rejected',
            'pending' => 'submission_pending',
            default => 'submitted',
        };
        $this->auditService->record($request, $request->user(), $companyId, $action, 'fbr', $submitted, $old, ['fbr_status' => $submitted->fbr_status->value, 'fbr_reference_number' => $submitted->fbr_reference_number, 'fbr_response_metadata' => $submitted->fbr_response_metadata]);

        return new InvoiceResource($submitted);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

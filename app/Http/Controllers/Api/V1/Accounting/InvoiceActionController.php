<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Services\Accounting\InvoicePostingService;
use App\Services\AuditService;
use Illuminate\Http\Request;

class InvoiceActionController extends Controller
{
    public function __construct(private readonly InvoicePostingService $postingService, private readonly AuditService $auditService) {}

    public function post(Request $request, string $invoice): InvoiceResource
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.post'), 403);
        $model = Invoice::query()->where('company_id', $companyId)->findOrFail($invoice);
        $old = $model->toArray();
        $posted = $this->postingService->post($companyId, $request->user(), $model);
        if ($old['journal_id'] === null) {
            $this->auditService->record($request, $request->user(), $companyId, 'post', 'invoicing', $posted, $old, $posted->withoutRelations()->toArray());
        }

        return new InvoiceResource($posted);
    }

    public function void(Request $request, string $invoice): InvoiceResource
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'accounting.post'), 403);
        $data = $request->validate(['posting_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $model = Invoice::query()->where('company_id', $companyId)->findOrFail($invoice);
        $old = $model->toArray();
        $voided = $this->postingService->void($companyId, $request->user(), $model, $data['posting_date'], $data['reason']);
        $this->auditService->record($request, $request->user(), $companyId, 'void', 'invoicing', $voided, $old, $voided->withoutRelations()->toArray());

        return new InvoiceResource($voided);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

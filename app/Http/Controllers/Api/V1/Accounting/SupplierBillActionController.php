<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupplierBillResource;
use App\Models\SupplierBill;
use App\Services\Accounting\SupplierBillPostingService;
use App\Services\AuditService;
use Illuminate\Http\Request;

class SupplierBillActionController extends Controller
{
    public function __construct(private readonly SupplierBillPostingService $service, private readonly AuditService $auditService) {}

    public function post(Request $request, string $bill): SupplierBillResource
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'supplier_bills.post'), 403);
        $model = SupplierBill::query()->where('company_id', $companyId)->findOrFail($bill);
        $old = $model->toArray();
        $posted = $this->service->post($companyId, $request->user(), $model);
        if ($old['journal_id'] === null) {
            $this->auditService->record($request, $request->user(), $companyId, 'post', 'accounts_payable', $posted, $old, $posted->withoutRelations()->toArray());
        }

        return new SupplierBillResource($posted);
    }

    public function void(Request $request, string $bill): SupplierBillResource
    {
        $companyId = $this->companyId($request);
        abort_unless($request->user()->hasCompanyPermission($companyId, 'supplier_bills.post'), 403);
        $data = $request->validate(['posting_date' => ['required', 'date'], 'reason' => ['required', 'string', 'max:2000']]);
        $model = SupplierBill::query()->where('company_id', $companyId)->findOrFail($bill);
        $old = $model->toArray();
        $voided = $this->service->void($companyId, $request->user(), $model, $data['posting_date'], $data['reason']);
        $this->auditService->record($request, $request->user(), $companyId, 'void', 'accounts_payable', $voided, $old, $voided->withoutRelations()->toArray());

        return new SupplierBillResource($voided);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

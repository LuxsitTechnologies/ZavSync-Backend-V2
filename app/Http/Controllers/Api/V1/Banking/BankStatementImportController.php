<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Banking\ConfirmBankStatementImportRequest;
use App\Http\Requests\Api\V1\Banking\PreviewBankStatementImportRequest;
use App\Http\Resources\BankStatementImportResource;
use App\Models\BankStatementImport;
use App\Services\AuditService;
use App\Services\Banking\BankStatementImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BankStatementImportController extends Controller
{
    public function __construct(private readonly BankStatementImportService $service, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);

        return BankStatementImportResource::collection(BankStatementImport::query()->where('company_id', $this->companyId($request))->with('financialAccount')->latest()->get());
    }

    public function preview(PreviewBankStatementImportRequest $request): BankStatementImportResource
    {
        $import = $this->service->preview($this->companyId($request), $request->user(), $request->file('file'), $request->safe()->except('file'), $this->idempotencyKey($request));
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'preview_import', 'banking', $import, null, $import->withoutRelations()->toArray());

        return new BankStatementImportResource($import);
    }

    public function confirm(ConfirmBankStatementImportRequest $request, string $statementImport): BankStatementImportResource
    {
        $import = BankStatementImport::query()->where('company_id', $this->companyId($request))->findOrFail($statementImport);
        $old = $import->toArray();
        $import = $this->service->confirm($this->companyId($request), $import);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'confirm_import', 'banking', $import, $old, $import->withoutRelations()->toArray());

        return new BankStatementImportResource($import);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_if(! is_string($key) || trim($key) === '', 422, 'Idempotency-Key header is required.');

        return trim($key);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

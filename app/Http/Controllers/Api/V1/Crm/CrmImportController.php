<?php

namespace App\Http\Controllers\Api\V1\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Crm\ConfirmCrmImportRequest;
use App\Http\Requests\Api\V1\Crm\PreviewCrmImportRequest;
use App\Http\Resources\CrmImportResource;
use App\Models\CrmImport;
use App\Services\Crm\CrmImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CrmImportController extends Controller
{
    public function __construct(private readonly CrmImportService $imports) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);

        return CrmImportResource::collection(CrmImport::query()->where('company_id', $this->companyId($request))->with('rows')->latest()->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function preview(PreviewCrmImportRequest $request): CrmImportResource
    {
        return new CrmImportResource($this->imports->preview($request, $request->validated()));
    }

    public function show(Request $request, string $import): CrmImportResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'crm.view'), 403);

        return new CrmImportResource($this->import($request, $import)->load('rows'));
    }

    public function confirm(ConfirmCrmImportRequest $request, string $import): CrmImportResource
    {
        return new CrmImportResource($this->imports->confirm($request, $this->import($request, $import), $request->validated()));
    }

    private function import(Request $request, string $id): CrmImport
    {
        return CrmImport::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

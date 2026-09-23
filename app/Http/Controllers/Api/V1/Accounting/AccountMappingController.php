<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\UpdateAccountMappingRequest;
use App\Http\Resources\AccountMappingResource;
use App\Models\AccountMapping;
use App\Services\Accounting\AccountMappingService;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountMappingController extends Controller
{
    public function __construct(private readonly AccountMappingService $mappingService, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'accounting.view'), 403);

        return AccountMappingResource::collection($this->mappingService->list($this->companyId($request)));
    }

    public function update(UpdateAccountMappingRequest $request, string $key): AccountMappingResource
    {
        $old = AccountMapping::query()->where('company_id', $this->companyId($request))->where('key', $key)->first()?->toArray();
        $mapping = $this->mappingService->set($this->companyId($request), $key, $request->validated('account_id'), $request->user()->id);
        $this->auditService->record($request, $request->user(), $this->companyId($request), 'update', 'accounting_configuration', $mapping, $old, $mapping->toArray());

        return new AccountMappingResource($mapping);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

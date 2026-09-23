<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Banking\StoreFinancialAccountRequest;
use App\Http\Requests\Api\V1\Banking\UpdateFinancialAccountRequest;
use App\Http\Resources\FinancialAccountResource;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Services\AuditService;
use App\Services\Banking\BankingReportingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class FinancialAccountController extends Controller
{
    public function __construct(private readonly BankingReportingService $reportingService, private readonly AuditService $auditService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);
        $accounts = $this->query($request)->with('glAccount')->orderBy('name')->get();
        $accounts->each(function (FinancialAccount $account): void {
            $report = $this->reportingService->bankGl($account);
            $account->setAttribute('book_balance', $report['book_balance']);
            $account->setAttribute('statement_balance', $report['statement_balance']);
        });

        return FinancialAccountResource::collection($accounts);
    }

    public function store(StoreFinancialAccountRequest $request): FinancialAccountResource
    {
        $account = DB::transaction(function () use ($request): FinancialAccount {
            Company::query()->lockForUpdate()->findOrFail($this->companyId($request));
            $data = $request->validated();
            if ($data['is_default']) {
                $this->query($request)->where('type', $data['type'])->update(['is_default' => false]);
            }
            $account = FinancialAccount::query()->create([...$data, 'company_id' => $this->companyId($request), 'created_by' => $request->user()->id]);
            $this->auditService->record($request, $request->user(), $this->companyId($request), 'create', 'banking', $account, null, $account->toArray());

            return $account;
        });

        return new FinancialAccountResource($account->load('glAccount'));
    }

    public function show(Request $request, string $financialAccount): FinancialAccountResource
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.view'), 403);

        return new FinancialAccountResource($this->query($request)->with('glAccount')->findOrFail($financialAccount));
    }

    public function update(UpdateFinancialAccountRequest $request, string $financialAccount): FinancialAccountResource
    {
        $account = DB::transaction(function () use ($request, $financialAccount): FinancialAccount {
            Company::query()->lockForUpdate()->findOrFail($this->companyId($request));
            $account = $this->query($request)->lockForUpdate()->findOrFail($financialAccount);
            $old = $account->toArray();
            $data = $request->validated();
            if ($data['is_default']) {
                $this->query($request)->where('type', $data['type'])->whereKeyNot($account->id)->update(['is_default' => false]);
            }
            $account->update([...$data, 'updated_by' => $request->user()->id]);
            $this->auditService->record($request, $request->user(), $this->companyId($request), $data['is_active'] ? 'update' : 'deactivate', 'banking', $account, $old, $account->fresh()->toArray());

            return $account->fresh('glAccount');
        });

        return new FinancialAccountResource($account);
    }

    private function query(Request $request): Builder
    {
        return FinancialAccount::query()->where('company_id', $this->companyId($request));
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

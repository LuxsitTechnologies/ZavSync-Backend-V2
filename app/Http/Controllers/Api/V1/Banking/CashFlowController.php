<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Services\Banking\BankingReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function __construct(private readonly BankingReportingService $service) {}

    public function current(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json($this->service->currentCash($this->companyId($request), $request->string('as_of')->toString() ?: null));
    }

    public function forecast(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $validated = $request->validate(['horizon' => ['nullable', 'integer', 'in:7,30,60,90'], 'as_of' => ['nullable', 'date']]);

        return response()->json($this->service->forecast($this->companyId($request), (int) ($validated['horizon'] ?? 30), $validated['as_of'] ?? null));
    }

    public function movement(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $validated = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $to = $validated['to'] ?? now()->toDateString();
        $from = $validated['from'] ?? CarbonImmutable::parse($to)->startOfMonth()->toDateString();

        return response()->json($this->service->cashMovement($this->companyId($request), $from, $to));
    }

    public function bankGl(Request $request, string $financialAccount): JsonResponse
    {
        $this->authorizeView($request);
        $account = FinancialAccount::query()->where('company_id', $this->companyId($request))->where('type', 'bank')->with('glAccount')->findOrFail($financialAccount);

        return response()->json($this->service->bankGl($account, $request->string('as_of')->toString() ?: null));
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'banking.cashflow'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

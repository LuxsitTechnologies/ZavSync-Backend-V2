<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\FinancialReportRequest;
use App\Services\Accounting\FinancialReportService;
use Illuminate\Http\JsonResponse;

class FinancialReportController extends Controller
{
    public function __construct(private readonly FinancialReportService $reports) {}

    public function trialBalance(FinancialReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->trialBalance((string) $request->attributes->get('company_id'), $request->validated('from'), $request->validated('to')));
    }

    public function profitAndLoss(FinancialReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->profitAndLoss((string) $request->attributes->get('company_id'), $request->validated('from'), $request->validated('to')));
    }

    public function balanceSheet(FinancialReportRequest $request): JsonResponse
    {
        $asOf = $request->validated('as_of') ?? $request->validated('to') ?? now()->toDateString();

        return response()->json($this->reports->balanceSheet((string) $request->attributes->get('company_id'), $asOf));
    }

    public function comparative(FinancialReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->comparativeProfitAndLoss((string) $request->attributes->get('company_id'), $request->validated('from'), $request->validated('to'), $request->validated('comparison_from'), $request->validated('comparison_to')));
    }
}

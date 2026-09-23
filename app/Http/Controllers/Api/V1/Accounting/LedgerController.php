<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Accounting\FinancialReportRequest;
use App\Http\Requests\Api\V1\Accounting\LedgerRequest;
use App\Services\Accounting\FinancialReportService;
use Illuminate\Http\JsonResponse;

class LedgerController extends Controller
{
    public function __construct(private readonly FinancialReportService $reports) {}

    public function index(LedgerRequest $request): JsonResponse
    {
        return response()->json($this->reports->generalLedger((string) $request->attributes->get('company_id'), $request->validated()));
    }

    public function trialBalance(FinancialReportRequest $request): JsonResponse
    {
        return response()->json($this->reports->trialBalance((string) $request->attributes->get('company_id'), $request->validated('from'), $request->validated('to')));
    }
}

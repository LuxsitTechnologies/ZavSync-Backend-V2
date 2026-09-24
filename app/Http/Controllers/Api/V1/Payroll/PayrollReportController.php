<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollBatch;
use App\Services\Payroll\PayrollReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollReportController extends Controller
{
    public function __construct(private readonly PayrollReportingService $reports) {}

    public function register(Request $request, string $batch): JsonResponse
    {
        $this->authorize($request);

        return response()->json(['data' => $this->reports->register($this->companyId($request), $this->batch($request, $batch))]);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $this->reports->summary($this->companyId($request), $data)]);
    }

    public function liabilities(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(['data' => $this->reports->liabilities($this->companyId($request), $request->query('payroll_batch_id'))]);
    }

    public function employeeHistory(Request $request, string $employee): JsonResponse
    {
        $this->authorize($request);
        Employee::query()->where('company_id', $this->companyId($request))->findOrFail($employee);

        return response()->json(['data' => $this->reports->employeeHistory($this->companyId($request), $employee)]);
    }

    public function components(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return response()->json(['data' => $this->reports->components($this->companyId($request), $data)]);
    }

    public function reconciliation(Request $request, string $batch): JsonResponse
    {
        $this->authorize($request);

        return response()->json(['data' => $this->reports->reconciliation($this->companyId($request), $this->batch($request, $batch))]);
    }

    private function batch(Request $request, string $id): PayrollBatch
    {
        return PayrollBatch::query()->where('company_id', $this->companyId($request))->findOrFail($id);
    }

    private function authorize(Request $request): void
    {
        abort_unless($request->user()->hasCompanyPermission($this->companyId($request), 'payroll.reports'), 403);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

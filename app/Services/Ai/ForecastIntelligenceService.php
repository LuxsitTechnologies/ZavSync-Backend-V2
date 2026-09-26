<?php

namespace App\Services\Ai;

use App\Models\IntelligenceForecast;
use App\Services\Accounting\FinancialReportService;
use App\Services\Banking\BankingReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ForecastIntelligenceService
{
    public function __construct(private readonly BankingReportingService $banking, private readonly FinancialReportService $reports) {}

    /** @return array<int,IntelligenceForecast> */
    public function refresh(string $companyId): array
    {
        return [$this->cash($companyId), $this->revenue($companyId)];
    }

    public function cash(string $companyId, int $horizon = 30): IntelligenceForecast
    {
        try {
            $data = $this->banking->forecast($companyId, $horizon);
            $status = 'READY';
        } catch (ValidationException) {
            $data = ['horizon_days' => $horizon, 'reason' => 'A single-currency cash position is required.'];
            $status = 'INSUFFICIENT_DATA';
        }
        $fingerprint = hash('sha256', "cash|{$horizon}|".today()->toDateString());

        return IntelligenceForecast::query()->updateOrCreate(['company_id' => $companyId, 'fingerprint' => $fingerprint], ['metric' => 'cash_position', 'source_module' => 'banking', 'method' => 'AUTHORITATIVE_CASH_FORECAST', 'horizon_days' => $horizon, 'status' => $status, 'source_data' => $data, 'assumptions' => ['invoice_due_dates' => true, 'supplier_bill_due_dates' => true], 'projection_points' => $status === 'READY' ? [['date' => $data['through'], 'value_minor' => (int) $data['projected_cash']]] : [], 'confidence_bps' => $status === 'READY' ? 7000 : null, 'limitations' => $status === 'READY' ? 'Uses recorded due dates and excludes unrecorded future transactions.' : 'A single-currency cash position is required.', 'generated_at' => now()]);
    }

    public function revenue(string $companyId, int $horizon = 30): IntelligenceForecast
    {
        $end = CarbonImmutable::today()->endOfMonth();
        $history = collect(range(2, 0))->map(function (int $monthsAgo) use ($companyId, $end): array {
            $month = $end->subMonths($monthsAgo);
            $report = $this->reports->profitAndLoss($companyId, $month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString());

            return ['period' => $month->format('Y-m'), 'value_minor' => (int) $report['revenue'] + (int) $report['other_income']];
        });
        $nonZero = $history->where('value_minor', '!=', 0)->count();
        $status = $nonZero >= 2 ? 'READY' : 'INSUFFICIENT_DATA';
        $projection = $status === 'READY' ? intdiv((int) $history->sum('value_minor'), $history->count()) : 0;
        $fingerprint = hash('sha256', "revenue|{$horizon}|".today()->toDateString());

        return IntelligenceForecast::query()->updateOrCreate(['company_id' => $companyId, 'fingerprint' => $fingerprint], ['metric' => 'revenue_trend', 'source_module' => 'accounting', 'method' => 'THREE_PERIOD_AVERAGE', 'horizon_days' => $horizon, 'status' => $status, 'source_data' => ['history' => $history->all()], 'assumptions' => ['period_length_days' => $horizon], 'projection_points' => $status === 'READY' ? [['date' => today()->addDays($horizon)->toDateString(), 'value_minor' => $projection]] : [], 'confidence_bps' => $status === 'READY' ? 5000 : null, 'limitations' => $status === 'READY' ? 'Simple historical average; seasonality and unrecorded pipeline are excluded.' : 'At least two non-zero historical periods are required.', 'generated_at' => now()]);
    }
}

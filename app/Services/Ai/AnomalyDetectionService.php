<?php

namespace App\Services\Ai;

use App\Models\AnomalyResult;
use App\Services\Accounting\FinancialReportService;
use Carbon\CarbonImmutable;

class AnomalyDetectionService
{
    public function __construct(private readonly FinancialReportService $reports) {}

    /** @return array<int,AnomalyResult> */
    public function refresh(string $companyId, int $thresholdBps = 2000): array
    {
        $end = CarbonImmutable::today()->endOfMonth();
        $periods = collect(range(3, 0))->map(function (int $monthsAgo) use ($companyId, $end): array {
            $month = $end->subMonths($monthsAgo);
            $report = $this->reports->profitAndLoss($companyId, $month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString());

            return ['period' => $month->format('Y-m'), 'revenue' => (int) $report['revenue'] + (int) $report['other_income'], 'expense' => (int) $report['cost_of_sales'] + (int) $report['operating_expenses'] + (int) $report['other_expenses']];
        });
        $results = [];
        foreach (['revenue', 'expense'] as $metric) {
            $values = $periods->pluck($metric)->map(fn ($value): int => (int) $value)->all();
            $result = $this->evaluate($values, $thresholdBps);
            $fingerprint = hash('sha256', implode('|', [$metric, $end->format('Y-m'), 'ROLLING_AVERAGE']));
            AnomalyResult::query()->where('company_id', $companyId)->where('metric', "monthly_{$metric}")->where('status', 'ACTIVE')->where('fingerprint', '!=', $fingerprint)->update(['status' => 'CLEARED', 'evaluated_at' => now()]);
            if ($result['is_anomaly']) {
                $results[] = AnomalyResult::query()->updateOrCreate(['company_id' => $companyId, 'fingerprint' => $fingerprint], ['category' => 'ACCOUNTING', 'source_module' => 'accounting', 'metric' => "monthly_{$metric}", 'method' => 'ROLLING_AVERAGE', 'observed_value' => $result['observed'], 'expected_value' => $result['expected'], 'deviation_value' => $result['deviation'], 'deviation_bps' => $result['deviation_bps'], 'threshold_bps' => $thresholdBps, 'sample_size' => count($values), 'window_start' => $end->subMonths(3)->startOfMonth(), 'window_end' => $end, 'evaluated_at' => now(), 'status' => 'ACTIVE', 'source_metrics' => ['periods' => $periods->all()], 'explanation' => $this->explanation($metric, $result)]);
            } else {
                AnomalyResult::query()->where('company_id', $companyId)->where('fingerprint', $fingerprint)->update(['status' => 'CLEARED', 'evaluated_at' => now()]);
            }
        }

        return $results;
    }

    /** @param array<int,int> $values @return array{is_anomaly:bool,observed:int,expected:int,deviation:int,deviation_bps:?int,sample_size:int} */
    public function evaluate(array $values, int $thresholdBps): array
    {
        if (count($values) < 4) {
            return ['is_anomaly' => false, 'observed' => $values[array_key_last($values)] ?? 0, 'expected' => 0, 'deviation' => 0, 'deviation_bps' => null, 'sample_size' => count($values)];
        }
        $observed = (int) array_pop($values);
        $expected = intdiv(array_sum($values), count($values));
        $deviation = $observed - $expected;
        $deviationBps = $expected === 0 ? null : intdiv($deviation * 10000, abs($expected));

        return ['is_anomaly' => $deviationBps !== null && abs($deviationBps) >= $thresholdBps, 'observed' => $observed, 'expected' => $expected, 'deviation' => $deviation, 'deviation_bps' => $deviationBps, 'sample_size' => count($values) + 1];
    }

    /** @param array{deviation_bps:?int} $result */
    private function explanation(string $metric, array $result): string
    {
        $direction = ($result['deviation_bps'] ?? 0) >= 0 ? 'above' : 'below';

        return ucfirst($metric).' is '.abs((int) $result['deviation_bps'])." basis points {$direction} the prior three-month average.";
    }
}

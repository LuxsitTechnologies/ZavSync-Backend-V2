<?php

namespace App\Services\Ai;

class PriorityScoringService
{
    /** @param array{amount_minor?:int,overdue_days?:int,severity?:string,integrity_risk?:bool,deadline_days?:int,exposure_bps?:int,deviation_bps?:int,confidence_bps?:int} $factors
     * @return array{score:int,breakdown:array<string,int>}
     */
    public function score(array $factors): array
    {
        $severity = match ($factors['severity'] ?? 'INFO') {
            'CRITICAL' => 2500, 'HIGH' => 1800, 'WARNING' => 1000, default => 300,
        };
        $materiality = min(2500, intdiv(max(0, $factors['amount_minor'] ?? 0), 1000));
        $overdue = min(2000, max(0, $factors['overdue_days'] ?? 0) * 40);
        $integrity = ($factors['integrity_risk'] ?? false) ? 1800 : 0;
        $deadlineDays = $factors['deadline_days'] ?? 365;
        $deadline = $deadlineDays < 0 ? 1200 : max(0, 1000 - ($deadlineDays * 50));
        $exposure = min(1000, intdiv(max(0, $factors['exposure_bps'] ?? 0), 10));
        $deviation = min(1000, intdiv(abs($factors['deviation_bps'] ?? 0), 10));
        $confidence = min(10000, max(0, $factors['confidence_bps'] ?? 10000));
        $breakdown = compact('severity', 'materiality', 'overdue', 'integrity', 'deadline', 'exposure', 'deviation');
        $raw = min(10000, array_sum($breakdown));

        return ['score' => intdiv($raw * $confidence, 10000), 'breakdown' => $breakdown + ['confidence_adjustment_bps' => $confidence]];
    }
}

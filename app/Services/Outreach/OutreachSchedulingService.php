<?php

namespace App\Services\Outreach;

use App\Models\OutreachSequence;
use Carbon\CarbonImmutable;

class OutreachSchedulingService
{
    public function nextEligible(OutreachSequence $sequence, CarbonImmutable $after, int $waitMinutes = 0): CarbonImmutable
    {
        $candidate = $after->addMinutes($waitMinutes);
        if ($sequence->starts_at !== null && $candidate->lessThan($sequence->starts_at)) {
            $candidate = CarbonImmutable::instance($sequence->starts_at);
        }
        $candidate = $candidate->setTimezone($sequence->timezone);
        $weekdays = array_map('intval', $sequence->allowed_weekdays ?: [1, 2, 3, 4, 5]);
        [$startHour, $startMinute] = array_map('intval', explode(':', mb_substr($sequence->send_window_start, 0, 5)));
        [$endHour, $endMinute] = array_map('intval', explode(':', mb_substr($sequence->send_window_end, 0, 5)));

        for ($days = 0; $days < 8; $days++) {
            if (! in_array($candidate->dayOfWeekIso, $weekdays, true)) {
                $candidate = $candidate->addDay()->setTime($startHour, $startMinute);

                continue;
            }
            $start = $candidate->setTime($startHour, $startMinute);
            $end = $candidate->setTime($endHour, $endMinute);
            if ($candidate->lessThan($start)) {
                $candidate = $start;
            }
            if ($candidate->lessThanOrEqualTo($end)) {
                return $candidate->utc();
            }
            $candidate = $candidate->addDay()->setTime($startHour, $startMinute);
        }

        throw new OutreachException('INVALID_SEND_WINDOW', 'The sequence has no eligible sending window.');
    }
}

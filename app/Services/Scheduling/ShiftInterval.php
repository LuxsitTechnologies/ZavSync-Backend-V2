<?php

namespace App\Services\Scheduling;

use App\Exceptions\PlatformException;
use Carbon\CarbonImmutable;
use DateTimeZone;

class ShiftInterval
{
    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function resolve(string $date, string $startTime, string $endTime, string $timezone): array
    {
        if ($startTime === $endTime) {
            throw new PlatformException('SCHEDULE_SHIFT_ZERO_LENGTH', 'A shift must have different start and end times.', 422);
        }

        $endDate = $endTime < $startTime ? CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString() : $date;
        $start = $this->resolveLocal($date, $startTime, $timezone);
        $end = $this->resolveLocal($endDate, $endTime, $timezone);
        if ($end->getTimestamp() <= $start->getTimestamp()) {
            throw new PlatformException('SCHEDULE_SHIFT_INVALID_INTERVAL', 'The shift must end after it starts.', 422);
        }

        return [$start, $end];
    }

    private function resolveLocal(string $date, string $time, string $timezone): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $localTimestamp = CarbonImmutable::parse("{$date} {$time}:00", 'UTC')->getTimestamp();
        $transitions = $zone->getTransitions($localTimestamp - 172800, $localTimestamp + 172800);
        $offsets = array_unique(array_map(static fn (array $transition): int => $transition['offset'], $transitions ?: []));
        $offsets[] = $zone->getOffset(CarbonImmutable::createFromTimestampUTC($localTimestamp));
        $matches = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = CarbonImmutable::createFromTimestampUTC($localTimestamp - $offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === "{$date} {$time}") {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }
        if (count($matches) !== 1) {
            throw new PlatformException('SCHEDULE_LOCAL_TIME_INVALID', 'The scheduled local time is missing or ambiguous in the company timezone.', 422);
        }

        return reset($matches);
    }
}

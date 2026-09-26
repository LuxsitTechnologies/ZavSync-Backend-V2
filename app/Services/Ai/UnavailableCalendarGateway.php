<?php

namespace App\Services\Ai;

use App\Contracts\CalendarGateway;
use App\Exceptions\PlatformException;
use App\Models\CalendarProviderConnection;

class UnavailableCalendarGateway implements CalendarGateway
{
    public function available(): bool
    {
        return false;
    }

    public function synchronize(CalendarProviderConnection $connection): array
    {
        throw new PlatformException('CALENDAR_PROVIDER_NOT_CONFIGURED', 'A real Google or Microsoft calendar provider is not configured for this deployment.', 409);
    }
}

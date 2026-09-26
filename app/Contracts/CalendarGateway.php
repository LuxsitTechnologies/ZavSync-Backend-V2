<?php

namespace App\Contracts;

use App\Models\CalendarProviderConnection;

interface CalendarGateway
{
    public function available(): bool;

    /** @return array<int, array<string, mixed>> */
    public function synchronize(CalendarProviderConnection $connection): array;
}

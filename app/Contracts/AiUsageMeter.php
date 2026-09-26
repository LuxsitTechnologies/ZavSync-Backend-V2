<?php

namespace App\Contracts;

use App\Models\AiUsageRecord;

interface AiUsageMeter
{
    /** @param array<string, mixed> $usage */
    public function record(string $companyId, ?int $userId, array $usage): AiUsageRecord;

    public function assertWithinLimit(string $companyId, string $operation): void;
}

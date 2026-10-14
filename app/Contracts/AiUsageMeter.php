<?php

namespace App\Contracts;

use App\Models\AiProviderConfiguration;
use App\Models\AiUsageRecord;
use App\Services\Ai\AiChatRequest;

interface AiUsageMeter
{
    /** @param array<string, mixed> $usage */
    public function record(string $companyId, ?int $userId, array $usage, ?string $reservationId = null): AiUsageRecord;

    public function reserveChat(string $companyId, string $operation, AiChatRequest $request, AiProviderConfiguration $configuration): ?string;

    /** @param array<int, string> $texts */
    public function reserveEmbedding(string $companyId, string $operation, array $texts): ?string;

    public function release(?string $reservationId): void;

    public function assertWithinLimit(string $companyId, string $operation): void;
}

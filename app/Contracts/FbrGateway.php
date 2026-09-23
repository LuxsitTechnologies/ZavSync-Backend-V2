<?php

namespace App\Contracts;

use App\Services\Fbr\FbrSubmissionResult;

interface FbrGateway
{
    /** @param array<string, mixed> $payload */
    public function submit(array $payload, string $idempotencyKey): FbrSubmissionResult;
}

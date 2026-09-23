<?php

namespace App\Services\Fbr;

use App\Enums\FbrSubmissionStatus;

class FbrSubmissionResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public readonly FbrSubmissionStatus $status, public readonly ?string $referenceNumber, public readonly array $metadata, public readonly ?string $message = null) {}
}

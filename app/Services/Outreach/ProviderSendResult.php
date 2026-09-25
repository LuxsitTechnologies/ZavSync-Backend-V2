<?php

namespace App\Services\Outreach;

class ProviderSendResult
{
    /** @param array<string, mixed> $response */
    public function __construct(public readonly string $providerMessageId, public readonly array $response = []) {}
}

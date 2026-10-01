<?php

namespace App\Services\Fbr;

class FbrSubmissionContext
{
    public function __construct(public readonly string $endpoint, #[\SensitiveParameter] public readonly string $credential, public readonly string $environment) {}
}

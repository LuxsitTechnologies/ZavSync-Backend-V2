<?php

namespace App\Contracts;

use App\Models\User;

interface AiToolRegistry
{
    /** @return array<int, array<string, mixed>> */
    public function definitions(User $user, string $companyId): array;

    /** @param array<string, mixed> $arguments @return array<string, mixed> */
    public function execute(User $user, string $companyId, string $toolName, array $arguments, ?string $messageId = null): array;
}

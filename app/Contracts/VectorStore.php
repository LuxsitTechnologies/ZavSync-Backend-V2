<?php

namespace App\Contracts;

use App\Models\User;

interface VectorStore
{
    /** @return array<int, array<string, mixed>> */
    /** @param array{source_ids?:array<int,string>,chunk_ids?:array<int,string>} $filters */
    public function search(User $user, string $companyId, string $query, int $limit = 6, array $filters = []): array;

    public function driver(): string;

    public function available(): bool;
}

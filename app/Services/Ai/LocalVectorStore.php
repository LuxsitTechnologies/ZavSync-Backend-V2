<?php

namespace App\Services\Ai;

use App\Contracts\VectorStore;
use App\Models\User;

class LocalVectorStore implements VectorStore
{
    public function __construct(private readonly KnowledgeRetrievalService $retrieval) {}

    public function search(User $user, string $companyId, string $query, int $limit = 6, array $filters = []): array
    {
        return $this->retrieval->search($user, $companyId, $query, $limit, $filters);
    }

    public function driver(): string
    {
        return 'local_bounded';
    }

    public function available(): bool
    {
        return true;
    }
}

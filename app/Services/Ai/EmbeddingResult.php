<?php

namespace App\Services\Ai;

class EmbeddingResult
{
    /** @param array<int, array<int, int>> $vectors */
    public function __construct(
        public readonly array $vectors,
        public readonly int $inputTokens,
        public readonly int $costMinor,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $latencyMs = 0,
    ) {}
}

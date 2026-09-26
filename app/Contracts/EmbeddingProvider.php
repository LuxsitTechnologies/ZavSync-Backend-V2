<?php

namespace App\Contracts;

use App\Models\AiProviderConfiguration;
use App\Services\Ai\EmbeddingResult;

interface EmbeddingProvider
{
    /** @param array<int, string> $texts */
    public function embed(array $texts, AiProviderConfiguration $configuration): EmbeddingResult;
}

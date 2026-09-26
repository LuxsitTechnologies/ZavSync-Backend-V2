<?php

namespace App\Services\Ai;

use App\Contracts\EmbeddingProvider;
use App\Exceptions\PlatformException;
use App\Models\AiProviderConfiguration;

class UnavailableEmbeddingProvider implements EmbeddingProvider
{
    public function embed(array $texts, AiProviderConfiguration $configuration): EmbeddingResult
    {
        throw new PlatformException('AI_PROVIDER_NOT_CONFIGURED', 'An embedding provider is not configured for this environment.', 409);
    }
}

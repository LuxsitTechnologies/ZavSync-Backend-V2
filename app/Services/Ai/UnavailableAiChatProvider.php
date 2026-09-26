<?php

namespace App\Services\Ai;

use App\Contracts\AiChatProvider;
use App\Exceptions\PlatformException;
use App\Models\AiProviderConfiguration;

class UnavailableAiChatProvider implements AiChatProvider
{
    public function chat(AiChatRequest $request, AiProviderConfiguration $configuration): AiChatResult
    {
        throw new PlatformException('AI_PROVIDER_NOT_CONFIGURED', 'An AI provider is not configured for this environment.', 409);
    }
}

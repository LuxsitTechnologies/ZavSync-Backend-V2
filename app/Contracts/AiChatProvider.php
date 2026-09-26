<?php

namespace App\Contracts;

use App\Models\AiProviderConfiguration;
use App\Services\Ai\AiChatRequest;
use App\Services\Ai\AiChatResult;

interface AiChatProvider
{
    public function chat(AiChatRequest $request, AiProviderConfiguration $configuration): AiChatResult;
}

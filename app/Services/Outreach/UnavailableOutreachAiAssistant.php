<?php

namespace App\Services\Outreach;

use App\Contracts\OutreachAiAssistant;
use App\Exceptions\OutreachException;

class UnavailableOutreachAiAssistant implements OutreachAiAssistant
{
    public function draft(string $prompt, array $context): array
    {
        throw new OutreachException('AI_PROVIDER_NOT_CONFIGURED', 'AI-assisted drafting is not configured. You can continue authoring manually.', 409);
    }
}

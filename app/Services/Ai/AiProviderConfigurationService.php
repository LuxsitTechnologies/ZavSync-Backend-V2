<?php

namespace App\Services\Ai;

use App\Exceptions\PlatformException;
use App\Models\AiProviderConfiguration;

class AiProviderConfigurationService
{
    public function requireEnabled(string $companyId): AiProviderConfiguration
    {
        $configuration = AiProviderConfiguration::query()->where('company_id', $companyId)->first();
        if ($configuration === null || ! $configuration->is_enabled || blank($configuration->api_key)) {
            throw new PlatformException('AI_PROVIDER_NOT_CONFIGURED', 'Configure and enable an AI provider before using this feature.', 409);
        }

        return $configuration;
    }
}

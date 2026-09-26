<?php

namespace App\Http\Controllers\Api\V1\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Ai\UpdateAiProviderConfigurationRequest;
use App\Models\AiProviderConfiguration;
use App\Models\CompanySetting;
use App\Services\AuditService;
use App\Services\Platform\PlatformAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiProviderConfigurationController extends Controller
{
    public function __construct(private readonly PlatformAccessService $access, private readonly AuditService $audit) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $this->access->authorize($request->user(), $companyId, 'ai.providers.view');
        $configuration = AiProviderConfiguration::query()->where('company_id', $companyId)->first();

        return response()->json(['configuration' => $configuration, 'has_api_key' => filled($configuration?->api_key)]);
    }

    public function update(UpdateAiProviderConfigurationRequest $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $data = $request->validated();
        $configuration = AiProviderConfiguration::query()->where('company_id', $companyId)->first();
        $old = $configuration?->only(['provider', 'chat_model', 'embedding_model', 'is_enabled']);
        if (blank($data['api_key'] ?? null)) {
            unset($data['api_key']);
        }
        if (($data['is_enabled'] ?? false) && blank($data['api_key'] ?? $configuration?->api_key)) {
            return response()->json(['message' => 'An API key is required before enabling the provider.', 'errors' => ['api_key' => ['An API key is required before enabling the provider.']]], 422);
        }
        if (($data['is_enabled'] ?? false) && ! CompanySetting::query()->where('company_id', $companyId)->value('ai_allow_external_provider')) {
            return response()->json(['message' => 'External AI providers are disabled in company settings.', 'error_code' => 'AI_EXTERNAL_PROVIDER_DISABLED'], 409);
        }
        $configuration = AiProviderConfiguration::query()->updateOrCreate(['company_id' => $companyId], [...$data, 'updated_by' => $request->user()->id]);
        $this->audit->record($request, $request->user(), $companyId, 'ai_provider_configuration_updated', 'ai', $configuration, $old, $configuration->only(['provider', 'chat_model', 'embedding_model', 'is_enabled']));

        return response()->json(['configuration' => $configuration, 'has_api_key' => filled($configuration->api_key)]);
    }

    private function companyId(Request $request): string
    {
        return (string) $request->attributes->get('company_id');
    }
}

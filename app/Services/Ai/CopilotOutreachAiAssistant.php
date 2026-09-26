<?php

namespace App\Services\Ai;

use App\Contracts\AiChatProvider;
use App\Contracts\AiUsageMeter;
use App\Contracts\OutreachAiAssistant;
use App\Exceptions\PlatformException;

class CopilotOutreachAiAssistant implements OutreachAiAssistant
{
    public function __construct(private readonly AiChatProvider $provider, private readonly AiProviderConfigurationService $configurations, private readonly AiUsageMeter $usage) {}

    public function draft(string $prompt, array $context): array
    {
        $companyId = (string) ($context['company_id'] ?? '');
        if ($companyId === '') {
            throw new PlatformException('COMPANY_CONTEXT_REQUIRED', 'Company context is required for AI-assisted outreach drafting.', 422);
        }
        $configuration = $this->configurations->requireEnabled($companyId);
        $this->usage->assertWithinLimit($companyId, 'OUTREACH_DRAFT');
        $providerContext = $context;
        unset($providerContext['company_id'], $providerContext['user_id']);
        $schema = [
            'type' => 'object',
            'properties' => [
                'subject' => ['type' => 'string'],
                'body_text' => ['type' => 'string'],
                'body_html' => ['type' => ['string', 'null']],
            ],
            'required' => ['subject', 'body_text', 'body_html'],
            'additionalProperties' => false,
        ];
        $result = $this->provider->chat(new AiChatRequest('Draft outreach copy only. Never send, enroll, or mutate CRM data. Return structured subject, body_text, and optional body_html.', [['role' => 'user', 'content' => $prompt]], [['context' => $providerContext]], [], [], $schema), $configuration);
        $subject = $result->structured['subject'] ?? null;
        $bodyText = $result->structured['body_text'] ?? null;
        if (! is_string($subject) || ! is_string($bodyText)) {
            throw new PlatformException('AI_PROVIDER_INVALID_RESPONSE', 'The AI provider did not return a valid outreach draft.', 502);
        }
        $this->usage->record($companyId, isset($context['user_id']) ? (int) $context['user_id'] : null, ['provider' => $result->provider, 'model' => $result->model, 'operation' => 'OUTREACH_DRAFT', 'input_tokens' => $result->inputTokens, 'output_tokens' => $result->outputTokens, 'cost_minor' => $result->costMinor, 'metadata' => ['latency_ms' => $result->latencyMs]]);

        return ['subject' => $subject, 'body_text' => $bodyText, 'body_html' => is_string($result->structured['body_html'] ?? null) ? $result->structured['body_html'] : null];
    }
}

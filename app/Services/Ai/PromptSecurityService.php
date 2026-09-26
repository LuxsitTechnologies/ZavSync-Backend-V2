<?php

namespace App\Services\Ai;

use App\Exceptions\PlatformException;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PromptSecurityService
{
    /** @var array<int, string> */
    private const BLOCKED_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|system|developer)\s+instructions?/i',
        '/reveal\s+(the\s+)?(system\s+prompt|api\s*key|secret|credentials?)/i',
        '/dump\s+(all\s+)?(companies|tenants|users|database)/i',
        '/bypass\s+(permissions?|authorization|tenant)/i',
        '/(?:execute|run)\s+(?:this\s+)?sql/i',
        '/(?:show|reveal|retrieve)\s+(?:another|other)\s+(?:company|tenant)(?:\'s)?\s+(?:data|records?)/i',
        '/(?:call|fetch|open)\s+https?:\/\//i',
    ];

    public function assertSafeUserPrompt(User $user, string $companyId, string $content, Request $request): void
    {
        if (! $this->isInjectionLike($content)) {
            return;
        }

        SecurityEvent::query()->create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'type' => 'AI_PROMPT_REJECTED',
            'result' => 'DENIED',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'correlation_id' => $request->attributes->get('correlation_id'),
            'metadata' => ['reason' => 'prompt_injection_or_exfiltration_pattern', 'content_hash' => hash('sha256', $content)],
        ]);

        throw new PlatformException('AI_PROMPT_REJECTED', 'The request was blocked by AI security controls.', 422);
    }

    public function isSafeKnowledge(string $content): bool
    {
        return ! $this->isInjectionLike($content);
    }

    private function isInjectionLike(string $content): bool
    {
        foreach (self::BLOCKED_PATTERNS as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return true;
            }
        }

        return false;
    }
}

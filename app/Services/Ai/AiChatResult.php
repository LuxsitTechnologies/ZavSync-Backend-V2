<?php

namespace App\Services\Ai;

class AiChatResult
{
    /**
     * @param  array<int, array{name:string,arguments:array<string, mixed>}>  $toolCalls
     * @param  array<int, int>  $citationOrdinals
     * @param  array<int, array{action_type:string,payload:array<string, mixed>}>  $proposedActions
     * @param  array<string, mixed>  $structured
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $toolCalls,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $costMinor,
        public readonly string $provider,
        public readonly string $model,
        public readonly array $citationOrdinals = [],
        public readonly array $proposedActions = [],
        public readonly array $structured = [],
        public readonly int $latencyMs = 0,
    ) {}
}

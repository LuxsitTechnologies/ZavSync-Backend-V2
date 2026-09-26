<?php

namespace App\Services\Ai;

class AiChatRequest
{
    /**
     * @param  array<int, array{role:string,content:string}>  $messages
     * @param  array<int, array<string, mixed>>  $knowledge
     * @param  array<int, array<string, mixed>>  $tools
     * @param  array<int, array<string, mixed>>  $toolResults
     * @param  array<string, mixed>  $responseSchema
     */
    public function __construct(
        public readonly string $systemInstructions,
        public readonly array $messages,
        public readonly array $knowledge,
        public readonly array $tools,
        public readonly array $toolResults = [],
        public readonly array $responseSchema = [],
    ) {}
}

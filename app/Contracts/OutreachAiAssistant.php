<?php

namespace App\Contracts;

interface OutreachAiAssistant
{
    /** @param array<string, mixed> $context @return array{subject:string,body_text:string,body_html:?string} */
    public function draft(string $prompt, array $context): array;
}

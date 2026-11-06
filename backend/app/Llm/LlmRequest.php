<?php

declare(strict_types=1);

namespace App\Llm;

final readonly class LlmRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  Constrains the output to this JSON schema when set.
     * @param  string  $purpose  What the call is for (segment, content); only used for the call log.
     * @param  int  $attempt  1 for the first try, 2 and up for repair rounds; only used for the call log.
     */
    public function __construct(
        public string $system,
        public array $messages,
        public int $maxTokens,
        public ?array $jsonSchema = null,
        public string $purpose = 'general',
        public ?string $promptVersion = null,
        public int $attempt = 1,
    ) {}
}

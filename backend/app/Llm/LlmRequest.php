<?php

declare(strict_types=1);

namespace App\Llm;

final readonly class LlmRequest
{
    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  array<string, mixed>|null  $jsonSchema  Constrains the output to this JSON schema when set.
     */
    public function __construct(
        public string $system,
        public array $messages,
        public int $maxTokens,
        public ?array $jsonSchema = null,
    ) {}
}

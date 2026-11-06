<?php

declare(strict_types=1);

namespace App\Llm;

/**
 * One streamed piece of text. Only the last chunk carries the stop reason and usage.
 */
final readonly class LlmChunk
{
    public function __construct(
        public string $text,
        public ?string $stopReason = null,
        public ?LlmUsage $usage = null,
    ) {}

    public function isLast(): bool
    {
        return $this->stopReason !== null;
    }
}

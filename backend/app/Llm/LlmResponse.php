<?php

declare(strict_types=1);

namespace App\Llm;

final readonly class LlmResponse
{
    // Values of the Anthropic "stop_reason" field the callers branch on.
    public const STOP_END_TURN = 'end_turn';

    public const STOP_MAX_TOKENS = 'max_tokens';

    public const STOP_REFUSAL = 'refusal';

    public function __construct(
        public string $text,
        public LlmUsage $usage,
        public string $stopReason = self::STOP_END_TURN,
    ) {}
}

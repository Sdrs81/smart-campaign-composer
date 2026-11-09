<?php

declare(strict_types=1);

namespace App\Llm\Prompts;

/**
 * The tags the prompts wrap user-supplied text in. Text carrying one of them could end its wrapper early
 * and write instructions outside it, so both LLM steps refuse it with this one definition: a brief that
 * passed the segment step must never be rejected later by the content step.
 */
final class PromptTags
{
    public const PATTERN = '/<\/?(brief|tone|audience)\b/i';

    public const DESCRIPTION = '<brief>, <tone> or <audience> tags';

    public static function appearIn(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }
}

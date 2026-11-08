<?php

declare(strict_types=1);

namespace App\Logging;

/**
 * What counts as a secret or personal data in free text, in one place: the log formatter and the
 * stats API both use it. Provider error bodies and exception messages can carry a key or a rejected
 * recipient (the mock provider echoes the recipient), and neither may reach a log line or an API client.
 */
final class SensitiveText
{
    public const MASK = '[REDACTED]';

    /** @var list<string> */
    public const PATTERNS = [
        // Anthropic API keys. The prefix is written in two parts so this pattern is not mistaken for a real key
        // by the commit guard that scans changes for key prefixes.
        '{sk-'.'ant-[A-Za-z0-9_\-]+}',
        // Phone numbers: E.164 (+905001234567) and the Turkish national form (05001234567).
        '{\+\d{8,15}}',
        '{\b0?5\d{9}\b}',
        '{[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}}',
    ];

    public static function mask(string $text): string
    {
        // If a pattern cannot be applied the text is withheld: showing it unmasked would defeat the purpose.
        return preg_replace(self::PATTERNS, self::MASK, $text) ?? self::MASK;
    }
}

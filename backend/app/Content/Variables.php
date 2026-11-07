<?php

declare(strict_types=1);

namespace App\Content;

/**
 * The only personalisation variables a message may use (K14), each with the text shown when the
 * customer has no value for it. The names match the columns AudienceService measures for K13.
 */
final class Variables
{
    public const FIRST_NAME = 'first_name';

    public const LAST_NAME = 'last_name';

    public const CITY = 'city';

    /** @var array<string, string> variable name => fallback text */
    private const FALLBACKS = [
        self::FIRST_NAME => 'Değerli müşterimiz',
        self::LAST_NAME => 'müşterimiz',
        self::CITY => 'bölgeniz',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::FALLBACKS);
    }

    public static function has(string $name): bool
    {
        return isset(self::FALLBACKS[$name]);
    }

    /** @throws \InvalidArgumentException for a name that is not a registered variable */
    public static function fallback(string $name): string
    {
        return self::FALLBACKS[$name]
            ?? throw new \InvalidArgumentException("Unknown variable '{$name}'; allowed: ".implode(', ', self::names()).'.');
    }
}

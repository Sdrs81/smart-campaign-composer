<?php

declare(strict_types=1);

namespace MockProvider;

/**
 * A bad recipient is a permanent error: the main app must not retry it.
 */
final class RecipientValidator
{
    // E.164: "+" and 8 to 15 digits, matching the customers.phone column.
    private const E164_PATTERN = '/^\+[1-9]\d{7,14}$/';

    public function isValid(Channel $channel, string $recipient): bool
    {
        return match ($channel) {
            Channel::Sms => preg_match(self::E164_PATTERN, $recipient) === 1,
            Channel::Email => filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false,
        };
    }
}

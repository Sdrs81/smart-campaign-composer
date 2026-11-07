<?php

declare(strict_types=1);

namespace MockProvider;

enum Channel: string
{
    case Sms = 'sms';
    case Email = 'email';

    /**
     * Body fields a request for this channel must carry as non-empty strings.
     *
     * @return list<string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            self::Sms => ['to', 'body'],
            self::Email => ['to', 'subject', 'body'],
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Content;

use App\Enums\Channel;

/**
 * The text of one message as written, with its {{variables}} unfilled. Model output and a person's
 * edit are both turned into this before ContentValidator checks them.
 */
final readonly class MessageContent
{
    /** @param  string|null  $subject  email only; an SMS has none */
    public function __construct(
        public Channel $channel,
        public ?string $subject,
        public string $body,
    ) {}
}

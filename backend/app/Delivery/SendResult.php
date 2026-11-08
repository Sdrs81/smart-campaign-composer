<?php

declare(strict_types=1);

namespace App\Delivery;

final readonly class SendResult
{
    private function __construct(
        public SendOutcome $outcome,
        public ?int $retryAfterSeconds = null,
        public ?string $reason = null,
        public ?string $providerMessageId = null,
    ) {}

    public static function sent(string $providerMessageId): self
    {
        return new self(SendOutcome::Sent, providerMessageId: $providerMessageId);
    }

    /**
     * @param  ?int  $retryAfterSeconds  null when the provider sent no usable Retry-After header
     */
    public static function rateLimited(?int $retryAfterSeconds, string $reason): self
    {
        return new self(SendOutcome::RateLimited, $retryAfterSeconds, $reason);
    }

    public static function transient(string $reason): self
    {
        return new self(SendOutcome::Transient, reason: $reason);
    }

    public static function permanent(string $reason): self
    {
        return new self(SendOutcome::Permanent, reason: $reason);
    }
}

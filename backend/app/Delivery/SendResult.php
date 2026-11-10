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
        public ?DeliveryErrorCode $errorCode = null,
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
        return new self(SendOutcome::RateLimited, $retryAfterSeconds, $reason, errorCode: DeliveryErrorCode::ProviderBusy);
    }

    /** @param  DeliveryErrorCode  $errorCode  what the dashboard tells the marketer; a server error unless the provider was not reached */
    public static function transient(string $reason, DeliveryErrorCode $errorCode = DeliveryErrorCode::ProviderError): self
    {
        return new self(SendOutcome::Transient, reason: $reason, errorCode: $errorCode);
    }

    public static function permanent(string $reason): self
    {
        return new self(SendOutcome::Permanent, reason: $reason, errorCode: DeliveryErrorCode::ProviderRejected);
    }
}

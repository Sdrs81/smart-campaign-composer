<?php

declare(strict_types=1);

namespace App\Delivery;

final readonly class RetryDecision
{
    private function __construct(
        public ?int $delayMilliseconds,
        public ?DeliveryErrorCode $errorCode,
        public ?string $reason,
    ) {}

    public static function retryIn(int $delayMilliseconds): self
    {
        return new self($delayMilliseconds, null, null);
    }

    public static function giveUp(DeliveryErrorCode $errorCode, string $reason): self
    {
        return new self(null, $errorCode, $reason);
    }

    public function shouldRetry(): bool
    {
        return $this->delayMilliseconds !== null;
    }
}

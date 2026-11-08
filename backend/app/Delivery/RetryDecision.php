<?php

declare(strict_types=1);

namespace App\Delivery;

final readonly class RetryDecision
{
    private function __construct(
        public ?int $delayMilliseconds,
        public ?string $reason,
    ) {}

    public static function retryIn(int $delayMilliseconds): self
    {
        return new self($delayMilliseconds, null);
    }

    public static function giveUp(string $reason): self
    {
        return new self(null, $reason);
    }

    public function shouldRetry(): bool
    {
        return $this->delayMilliseconds !== null;
    }
}

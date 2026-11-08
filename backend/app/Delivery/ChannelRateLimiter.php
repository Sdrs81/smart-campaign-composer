<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use Illuminate\Support\Facades\Redis;

/**
 * The global outbound limit per channel, shared by every worker through Redis (K17). A worker waits for
 * its turn here instead of calling the provider and burning a retry on a 429.
 */
final readonly class ChannelRateLimiter
{
    private const WINDOW_SECONDS = 1;

    // The framework default of 750 ms would cap the real rate far below the limit, so waiters poll often.
    private const POLL_MILLISECONDS = 10;

    public function __construct(
        private int $permitsPerSecond,
        private int $maxWaitSeconds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('delivery.rate_per_second'),
            (int) config('delivery.rate_limit_max_wait_seconds'),
        );
    }

    /** Returns once this caller may send one message on the channel. */
    public function await(Channel $channel): void
    {
        Redis::throttle("send:{$channel->value}")
            ->allow($this->permitsPerSecond)
            ->every(self::WINDOW_SECONDS)
            ->block($this->maxWaitSeconds)
            ->sleep(self::POLL_MILLISECONDS)
            ->then(
                static fn () => null,
                fn () => throw ChannelRateLimitTimeout::waitingFor($channel, $this->maxWaitSeconds),
            );
    }
}

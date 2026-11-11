<?php

declare(strict_types=1);

namespace MockProvider;

use Redis;

/**
 * The provider's own limit per channel and second. Counting in Redis makes it
 * hold across all php -S workers.
 */
final class RateLimiter
{
    public const RETRY_AFTER_SECONDS = 1;

    private const PREFIX = 'mock:ratelimit:';

    // A bucket outlives its second only so a clock skew between workers cannot recreate it.
    private const BUCKET_TTL_SECONDS = 2;

    public function __construct(
        private readonly Redis $redis,
        private readonly int $limitPerSecond,
    ) {}

    public static function fromEnvironment(Redis $redis): self
    {
        $configured = getenv('SEND_RATE_PER_SECOND');

        return new self($redis, $configured === false || $configured === '' ? 100 : (int) $configured);
    }

    public function tryAcquire(Channel $channel, int $timestamp): bool
    {
        $bucket = self::PREFIX.$channel->value.':'.$timestamp;
        $count = $this->redis->incr($bucket);
        $this->redis->expire($bucket, self::BUCKET_TTL_SECONDS);

        return $count <= $this->limitPerSecond;
    }
}

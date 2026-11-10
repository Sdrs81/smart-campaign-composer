<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use Illuminate\Support\Facades\Redis;

/**
 * The global outbound limit per channel, shared by every worker through Redis (K17). A worker waits for
 * its turn here instead of calling the provider and burning a retry on a 429.
 */
final readonly class ChannelRateLimiter implements SendRateLimiter
{
    private const WINDOW_SECONDS = 1;

    // The framework default of 750 ms would cap the real rate far below the limit, so waiters poll often.
    private const POLL_MILLISECONDS = 10;

    // Per-second buckets only need to outlive the second so a slow reader (a test, a scrape) still sees them.
    private const BUCKET_TTL_SECONDS = 120;

    // Counts a granted call in its clock second and raises the channel's peak when the bucket beats it, in one
    // atomic script so concurrent workers cannot lose a peak. Same pattern as the mock provider's own counter.
    private const RAISE_PEAK_SCRIPT = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        redis.call('EXPIRE', KEYS[1], ARGV[1])
        local peak = tonumber(redis.call('GET', KEYS[2]) or '0')
        if current > peak then redis.call('SET', KEYS[2], current) end
        return current
        LUA;

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
                fn () => $this->recordGrant($channel),
                fn () => throw ChannelRateLimitTimeout::waitingFor($channel, $this->maxWaitSeconds),
            );
    }

    /**
     * The most calls this sender let through in any single clock second since the counters were last cleared.
     * Measured on our side: the provider's own peak is capped by its limit, so it cannot show that we stayed under it.
     */
    public function peakPerSecond(Channel $channel): int
    {
        return (int) Redis::get("send:peak:{$channel->value}:peak");
    }

    private function recordGrant(Channel $channel): void
    {
        Redis::eval(
            self::RAISE_PEAK_SCRIPT,
            2,
            "send:peak:{$channel->value}:second:".time(),
            "send:peak:{$channel->value}:peak",
            self::BUCKET_TTL_SECONDS,
        );
    }
}

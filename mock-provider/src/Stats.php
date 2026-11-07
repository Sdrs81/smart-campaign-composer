<?php

declare(strict_types=1);

namespace MockProvider;

use Redis;

/**
 * Counters kept in Redis so every php -S worker sees the same numbers.
 * They are independent of the main app, which is what proves "no duplicates".
 */
final class Stats
{
    private const PREFIX = 'mock:stats:';

    // Per-second buckets live a little longer than a second so a slow reader still sees them.
    private const BUCKET_TTL_SECONDS = 120;

    // Raises the peak only when the bucket beats it; one atomic script, so workers cannot lose a peak.
    private const RAISE_PEAK_SCRIPT = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        redis.call('EXPIRE', KEYS[1], ARGV[1])
        local peak = tonumber(redis.call('GET', KEYS[2]) or '0')
        if current > peak then redis.call('SET', KEYS[2], current) end
        return current
        LUA;

    public function __construct(private readonly Redis $redis) {}

    public function recordAccepted(Channel $channel, int $timestamp): void
    {
        $this->redis->incr(self::PREFIX.$channel->value.':total');
        $this->redis->eval(
            self::RAISE_PEAK_SCRIPT,
            [
                self::PREFIX.$channel->value.':second:'.$timestamp,
                self::PREFIX.$channel->value.':peak',
                (string) self::BUCKET_TTL_SECONDS,
            ],
            2,
        );
    }

    public function recordDuplicate(Channel $channel): void
    {
        $this->redis->incr(self::PREFIX.$channel->value.':duplicates');
    }

    /**
     * @return array<string, array{total: int, duplicates_prevented: int, peak_per_second: int}>
     */
    public function snapshot(): array
    {
        $snapshot = [];
        foreach (Channel::cases() as $channel) {
            $snapshot[$channel->value] = [
                'total' => $this->counter($channel, 'total'),
                'duplicates_prevented' => $this->counter($channel, 'duplicates'),
                'peak_per_second' => $this->counter($channel, 'peak'),
            ];
        }

        return $snapshot;
    }

    public function reset(): void
    {
        $keys = $this->redis->keys(self::PREFIX.'*');
        if (is_array($keys) && $keys !== []) {
            $this->redis->del($keys);
        }
    }

    private function counter(Channel $channel, string $name): int
    {
        return (int) $this->redis->get(self::PREFIX.$channel->value.':'.$name);
    }
}

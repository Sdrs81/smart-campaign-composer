<?php

declare(strict_types=1);

use MockProvider\RandomSource;

// The running mock provider uses Redis db 0; tests use db 1 so a reset here never wipes its counters.
const TEST_REDIS_DATABASE = 1;

function testRedis(): Redis
{
    $redis = new Redis;
    $redis->connect((string) (getenv('REDIS_HOST') ?: 'redis'), (int) (getenv('REDIS_PORT') ?: 6379));
    $redis->select(TEST_REDIS_DATABASE);

    return $redis;
}

// A fresh key per call keeps tests independent on the shared Redis.
function freshKey(): string
{
    return 'test-'.bin2hex(random_bytes(8));
}

/**
 * @param  Closure(): float  $unitInterval  Called once per draw
 */
function scriptedRandom(Closure $unitInterval, int $between = 0): RandomSource
{
    return new class($unitInterval, $between) implements RandomSource
    {
        public function __construct(private readonly Closure $unitInterval, private readonly int $between) {}

        public function unitInterval(): float
        {
            return ($this->unitInterval)();
        }

        public function between(int $min, int $max): int
        {
            return $this->between;
        }
    };
}

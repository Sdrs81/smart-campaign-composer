<?php

declare(strict_types=1);

use App\Delivery\ChannelRateLimiter;
use App\Delivery\ChannelRateLimitTimeout;
use App\Enums\Channel;
use Illuminate\Support\Facades\Redis;

const LIMIT_PER_SECOND = 100;
const MICROSECONDS_PER_SECOND = 1_000_000;

beforeEach(function (): void {
    Redis::del('send:sms', 'send:email');
});

/** The throttle window starts at the first permit, so the test starts just after a clock second begins. */
function startOfClockSecond(): void
{
    $microsecondsLeft = MICROSECONDS_PER_SECOND - (int) ((microtime(true) - floor(microtime(true))) * MICROSECONDS_PER_SECOND);
    usleep($microsecondsLeft + 20_000);
}

it('lets through at most the limit in every clock second, with a real Redis', function (): void {
    $limiter = new ChannelRateLimiter(LIMIT_PER_SECOND, 10);
    startOfClockSecond();

    $perSecond = [];
    for ($permit = 0; $permit < 250; $permit++) {
        $limiter->await(Channel::Sms);
        $second = (int) floor(microtime(true));
        $perSecond[$second] = ($perSecond[$second] ?? 0) + 1;
    }

    expect(array_sum($perSecond))->toBe(250)
        ->and(max($perSecond))->toBeLessThanOrEqual(LIMIT_PER_SECOND)
        ->and(count($perSecond))->toBeGreaterThanOrEqual(3);
});

it('does not slow one channel down because another is saturated', function (): void {
    $limiter = new ChannelRateLimiter(LIMIT_PER_SECOND, 10);
    startOfClockSecond();

    for ($permit = 0; $permit < LIMIT_PER_SECOND; $permit++) {
        $limiter->await(Channel::Sms);
    }

    $started = microtime(true);
    for ($permit = 0; $permit < LIMIT_PER_SECOND; $permit++) {
        $limiter->await(Channel::Email);
    }

    expect(microtime(true) - $started)->toBeLessThan(0.5);
});

it('says which channel was saturated when the wait runs out', function (): void {
    $limiter = new ChannelRateLimiter(1, 0);
    $limiter->await(Channel::Email);

    $limiter->await(Channel::Email);
})->throws(ChannelRateLimitTimeout::class, 'email');

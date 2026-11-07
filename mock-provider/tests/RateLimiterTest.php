<?php

declare(strict_types=1);

use MockProvider\Channel;
use MockProvider\RateLimiter;

const LIMIT = 3;

beforeEach(function (): void {
    $this->limiter = new RateLimiter(testRedis(), LIMIT);
    // A fresh second per test, so counters from other tests or earlier runs cannot leak in.
    $this->second = random_int(1_000_000_000, 2_000_000_000);
});

it('allows requests up to the limit and refuses the next one', function (): void {
    $results = array_map(
        fn () => $this->limiter->tryAcquire(Channel::Sms, $this->second),
        range(1, LIMIT + 1),
    );

    expect($results)->toBe([true, true, true, false]);
});

it('counts each channel on its own', function (): void {
    foreach (range(1, LIMIT) as $ignored) {
        $this->limiter->tryAcquire(Channel::Sms, $this->second);
    }

    expect($this->limiter->tryAcquire(Channel::Email, $this->second))->toBeTrue();
});

it('starts a new count in the next second', function (): void {
    foreach (range(1, LIMIT + 1) as $ignored) {
        $this->limiter->tryAcquire(Channel::Sms, $this->second);
    }

    expect($this->limiter->tryAcquire(Channel::Sms, $this->second + 1))->toBeTrue();
});

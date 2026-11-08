<?php

declare(strict_types=1);

use App\Delivery\RetryPolicy;
use App\Delivery\SendResult;

const BASE_DELAY_MS = 200;
const MAX_DELAY_MS = 10000;
const MAX_ATTEMPTS_TEST = 5;

/** A random source that always picks the lowest or highest allowed value. */
function retryPolicyPicking(string $pick = 'max'): RetryPolicy
{
    return new RetryPolicy(MAX_ATTEMPTS_TEST, BASE_DELAY_MS, MAX_DELAY_MS, fn (int $min, int $max): int => $pick === 'max' ? $max : $min);
}

it('waits between zero and base times two to the power of the attempt, capped', function (int $attempt, int $expectedCeiling): void {
    $bounds = [];
    $policy = new RetryPolicy(MAX_ATTEMPTS_TEST, BASE_DELAY_MS, MAX_DELAY_MS, function (int $min, int $max) use (&$bounds): int {
        $bounds = [$min, $max];

        return $max;
    });

    $decision = $policy->decide(SendResult::transient('boom'), $attempt);

    expect($bounds)->toBe([0, $expectedCeiling])
        ->and($decision->shouldRetry())->toBeTrue()
        ->and($decision->delayMilliseconds)->toBe($expectedCeiling);
})->with([
    'first retry' => [1, 200],
    'second retry' => [2, 400],
    'third retry' => [3, 800],
    'fourth retry' => [4, 1600],
]);

it('never waits longer than the cap', function (): void {
    $policy = new RetryPolicy(30, BASE_DELAY_MS, MAX_DELAY_MS, fn (int $min, int $max): int => $max);

    expect($policy->decide(SendResult::transient('boom'), 25)->delayMilliseconds)->toBe(MAX_DELAY_MS);
});

it('can pick zero as the lowest jittered wait', function (): void {
    expect(retryPolicyPicking('min')->decide(SendResult::transient('boom'), 3)->delayMilliseconds)->toBe(0);
});

it('lets Retry-After win over a shorter backoff', function (): void {
    $decision = retryPolicyPicking()->decide(SendResult::rateLimited(3, 'slow down'), 1);

    expect($decision->delayMilliseconds)->toBe(3000);
});

it('keeps the backoff when it is longer than Retry-After', function (): void {
    $decision = retryPolicyPicking()->decide(SendResult::rateLimited(1, 'slow down'), 4);

    expect($decision->delayMilliseconds)->toBe(1600);
});

it('falls back to the backoff when a 429 has no usable Retry-After', function (): void {
    $decision = retryPolicyPicking()->decide(SendResult::rateLimited(null, 'slow down'), 2);

    expect($decision->delayMilliseconds)->toBe(400);
});

it('gives up after the fifth failed attempt', function (string $kind): void {
    $result = $kind === 'rate' ? SendResult::rateLimited(1, 'slow down') : SendResult::transient('boom');

    $fourth = retryPolicyPicking()->decide($result, MAX_ATTEMPTS_TEST - 1);
    $fifth = retryPolicyPicking()->decide($result, MAX_ATTEMPTS_TEST);

    expect($fourth->shouldRetry())->toBeTrue()
        ->and($fifth->shouldRetry())->toBeFalse()
        ->and($fifth->delayMilliseconds)->toBeNull()
        ->and($fifth->reason)->toContain('5 attempts');
})->with(['transient' => ['transient'], 'rate limited' => ['rate']]);

it('never retries a permanent failure', function (): void {
    $decision = retryPolicyPicking()->decide(SendResult::permanent('Provider answered 400: bad recipient'), 1);

    expect($decision->shouldRetry())->toBeFalse()
        ->and($decision->reason)->toContain('bad recipient');
});

it('refuses to decide for a message that was sent', function (): void {
    retryPolicyPicking()->decide(SendResult::sent('msg-1'), 1);
})->throws(InvalidArgumentException::class, 'needs no retry decision');

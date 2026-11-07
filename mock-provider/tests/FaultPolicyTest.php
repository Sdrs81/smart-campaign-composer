<?php

declare(strict_types=1);

use MockProvider\Fault;
use MockProvider\FaultPolicy;

const DRAWS = 10000;

it('splits draws by the configured rates', function (): void {
    $draw = 0;
    // Midpoints never sit on a rate boundary, so float rounding cannot move a draw to the next fault.
    $random = scriptedRandom(function () use (&$draw): float {
        return ($draw++ + 0.5) / DRAWS;
    });
    $policy = new FaultPolicy($random, 10, 80, 0.05, 0.02, 0.01, 0.02);

    $counts = array_count_values(array_map(
        fn () => $policy->decide()->fault->value,
        range(1, DRAWS),
    ));

    expect($counts)->toBe([
        Fault::ServerError->value => 500,
        Fault::RateLimited->value => 200,
        Fault::Timeout->value => 100,
        Fault::AcceptThenServerError->value => 200,
        Fault::None->value => 9000,
    ]);
});

it('never fails when every rate is zero', function (): void {
    $policy = new FaultPolicy(scriptedRandom(fn () => 0.0), 10, 80, 0.0, 0.0, 0.0, 0.0);

    expect($policy->decide()->fault)->toBe(Fault::None);
});

it('takes latency from the random source inside the configured range', function (): void {
    $policy = new FaultPolicy(scriptedRandom(fn () => 0.99, between: 42), 10, 80, 0.05, 0.02, 0.01, 0.02);

    expect($policy->decide()->latencyMilliseconds)->toBe(42);
});

it('makes a timeout last longer than the client waits', function (): void {
    $policy = new FaultPolicy(scriptedRandom(fn () => 0.0), 10, 80, 0.0, 0.0, 1.0, 0.0);

    $decision = $policy->decide();

    expect($decision->fault)->toBe(Fault::Timeout)
        ->and($decision->latencyMilliseconds)->toBe(FaultPolicy::TIMEOUT_MILLISECONDS);
});

it('refuses rates that cannot be drawn from one chance', function (float $serverError, float $rateLimited): void {
    new FaultPolicy(scriptedRandom(fn () => 0.0), 10, 80, $serverError, $rateLimited, 0.0, 0.0);
})->with([
    'a negative rate' => [-0.1, 0.0],
    'rates adding up to more than 1' => [0.9, 0.5],
])->throws(InvalidArgumentException::class);

it('refuses a latency range where the minimum is above the maximum', function (): void {
    new FaultPolicy(scriptedRandom(fn () => 0.0), 80, 10, 0.0, 0.0, 0.0, 0.0);
})->throws(InvalidArgumentException::class);

it('uses a forced fault without latency or chance', function (): void {
    $policy = new FaultPolicy(scriptedRandom(fn () => 0.0, between: 42), 10, 80, 1.0, 0.0, 0.0, 0.0);

    $decision = $policy->decide(Fault::RateLimited);

    expect($decision->fault)->toBe(Fault::RateLimited)
        ->and($decision->latencyMilliseconds)->toBe(0);
});

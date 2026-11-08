<?php

declare(strict_types=1);

use MockProvider\Application;
use MockProvider\FaultPolicy;
use MockProvider\IdempotencyStore;
use MockProvider\RateLimiter;
use MockProvider\RecipientValidator;
use MockProvider\Response;
use MockProvider\Stats;

const SMS_BODY = ['to' => '+905000000001', 'body' => 'Merhaba'];

function buildApplication(array &$sleeps, int $rateLimit = 100): Application
{
    $redis = testRedis();

    return new Application(
        new Stats($redis),
        new IdempotencyStore($redis),
        new RecipientValidator,
        // Rates are zero: only the X-Mock-Fault header injects faults, so every test is deterministic.
        new FaultPolicy(scriptedRandom(fn () => 0.99), 0, 0, 0.0, 0.0, 0.0, 0.0),
        new RateLimiter($redis, $rateLimit),
        function (int $milliseconds) use (&$sleeps): void {
            $sleeps[] = $milliseconds;
        },
    );
}

/**
 * @param  array<string, string>  $headers  Server-style names, e.g. HTTP_X_MOCK_FAULT
 */
function postSms(Application $application, string $key, array $headers = []): Response
{
    return $application->handle('POST', '/sms', json_encode(SMS_BODY, JSON_THROW_ON_ERROR), ['HTTP_IDEMPOTENCY_KEY' => $key] + $headers);
}

function smsStats(Application $application): array
{
    return $application->handle('GET', '/stats', '', [])->body['sms'];
}

beforeEach(function (): void {
    $this->sleeps = [];
    $this->application = buildApplication($this->sleeps);
    $this->application->handle('DELETE', '/stats', '', []);
});

it('answers 429 with Retry-After for X-Mock-Fault 429 and counts nothing', function (): void {
    $response = postSms($this->application, freshKey(), ['HTTP_X_MOCK_FAULT' => '429']);

    expect($response->status)->toBe(429)
        ->and($response->headers)->toHaveKey('Retry-After')
        ->and((int) $response->headers['Retry-After'])->toBeGreaterThanOrEqual(1)
        ->and(smsStats($this->application)['total'])->toBe(0);
});

it('answers 500 for X-Mock-Fault 500 and counts nothing', function (): void {
    $response = postSms($this->application, freshKey(), ['HTTP_X_MOCK_FAULT' => '500']);

    expect($response->status)->toBe(500)
        ->and(smsStats($this->application)['total'])->toBe(0);
});

it('keeps the message after accept-then-500 and answers 200 to the retry with one record', function (): void {
    $key = freshKey();

    $first = postSms($this->application, $key, ['HTTP_X_MOCK_FAULT' => 'accept-then-500']);
    $retry = postSms($this->application, $key);

    expect($first->status)->toBe(500)
        ->and($retry->status)->toBe(200)
        ->and(smsStats($this->application))->toMatchArray(['total' => 1, 'duplicates_prevented' => 1]);
});

it('waits longer than the client does on X-Mock-Fault timeout and counts nothing', function (): void {
    $response = postSms($this->application, freshKey(), ['HTTP_X_MOCK_FAULT' => 'timeout']);

    expect($response->status)->toBe(504)
        ->and($this->sleeps)->toBe([FaultPolicy::TIMEOUT_MILLISECONDS])
        ->and(smsStats($this->application)['total'])->toBe(0);
});

it('rejects an unknown X-Mock-Fault value', function (): void {
    $response = postSms($this->application, freshKey(), ['HTTP_X_MOCK_FAULT' => 'explode']);

    expect($response->status)->toBe(400)
        ->and($response->body['error']['code'])->toBe('invalid_fault');
});

it('answers 429 with Retry-After when the channel is over its own limit', function (): void {
    $sleeps = [];
    $application = buildApplication($sleeps, rateLimit: 0);

    $response = postSms($application, freshKey());

    expect($response->status)->toBe(429)
        ->and($response->headers)->toHaveKey('Retry-After')
        ->and(smsStats($application)['total'])->toBe(0);
});

it('accepts a normal request', function (): void {
    $response = postSms($this->application, freshKey());

    expect($response->status)->toBe(200)
        ->and(smsStats($this->application)['total'])->toBe(1);
});

it('counts an accepted message in the second it arrived, even when its latency crosses into the next one', function (): void {
    $redis = testRedis();
    $application = new Application(
        new Stats($redis),
        new IdempotencyStore($redis),
        new RecipientValidator,
        new FaultPolicy(scriptedRandom(fn () => 0.99), 0, 0, 0.0, 0.0, 0.0, 0.0),
        new RateLimiter($redis, 100),
        // A real pause longer than a second, so the answer is certainly accepted in a later clock second.
        static fn (int $milliseconds) => usleep(1_100_000),
    );
    $application->handle('DELETE', '/stats', '', []);

    // Start just after a second begins, so reading the clock before the call cannot straddle a boundary.
    usleep(1_000_000 - (int) ((microtime(true) - floor(microtime(true))) * 1_000_000) + 50_000);
    $arrivedAt = time();

    expect(postSms($application, freshKey())->status)->toBe(200)
        ->and((int) $redis->get("mock:stats:sms:second:{$arrivedAt}"))->toBe(1)
        ->and((int) $redis->get('mock:stats:sms:second:'.($arrivedAt + 1)))->toBe(0);
});

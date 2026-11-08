<?php

declare(strict_types=1);

use App\Delivery\HttpMessageProvider;
use App\Delivery\SendOutcome;
use App\Delivery\SendResult;
use App\Enums\Channel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Needs the framework for the Http facade, so it lives in Feature although it touches no database.

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('delivery.provider.url', 'http://provider.test');
});

function sendSms(): SendResult
{
    return HttpMessageProvider::fromConfig()->send(Channel::Sms, ['to' => '+905551112233', 'body' => 'Hi'], 'key-123');
}

it('classifies a 2xx answer as sent and sends the Idempotency-Key header', function (): void {
    Http::fake(['provider.test/*' => Http::response(['id' => 'abc', 'status' => 'accepted'], 200)]);

    $result = sendSms();

    expect($result->outcome)->toBe(SendOutcome::Sent)
        ->and($result->providerMessageId)->toBe('abc');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://provider.test/sms'
        && $request->method() === 'POST'
        && $request->header('Idempotency-Key') === ['key-123']
        && $request->data() === ['to' => '+905551112233', 'body' => 'Hi']);
});

it('classifies 429 as rate limited and reads Retry-After', function (): void {
    Http::fake(['provider.test/*' => Http::response('slow down', 429, ['Retry-After' => '2'])]);

    $result = sendSms();

    expect($result->outcome)->toBe(SendOutcome::RateLimited)
        ->and($result->retryAfterSeconds)->toBe(2);
});

it('leaves retry-after empty when a 429 carries no usable header', function (): void {
    Http::fake(['provider.test/*' => Http::response('slow down', 429, ['Retry-After' => 'soon'])]);

    expect(sendSms()->retryAfterSeconds)->toBeNull();
});

it('classifies 5xx as transient', function (int $status): void {
    Http::fake(['provider.test/*' => Http::response('boom', $status)]);

    expect(sendSms()->outcome)->toBe(SendOutcome::Transient);
})->with([500, 503, 504]);

it('classifies a connection failure or timeout as transient', function (): void {
    Http::fake(['provider.test/*' => Http::failedConnection()]);

    expect(sendSms()->outcome)->toBe(SendOutcome::Transient);
});

it('classifies other 4xx as permanent', function (int $status): void {
    Http::fake(['provider.test/*' => Http::response('bad', $status)]);

    $result = sendSms();

    expect($result->outcome)->toBe(SendOutcome::Permanent)
        ->and($result->reason)->toContain((string) $status);
})->with([400, 404, 422]);

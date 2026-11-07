<?php

declare(strict_types=1);

use MockProvider\Channel;
use MockProvider\IdempotencyOutcome;
use MockProvider\IdempotencyStore;

const SMS_REQUEST = ['to' => '+905000000001', 'body' => 'Merhaba'];

const FIRST_RESPONSE = ['id' => 'first', 'status' => 'accepted'];

beforeEach(function (): void {
    $this->store = new IdempotencyStore(testRedis());
});

it('creates the entry on the first request', function (): void {
    $result = $this->store->remember(Channel::Sms, freshKey(), SMS_REQUEST, FIRST_RESPONSE);

    expect($result->outcome)->toBe(IdempotencyOutcome::Created)
        ->and($result->response)->toBe(FIRST_RESPONSE);
});

it('replays the first response for the same key and body', function (): void {
    $key = freshKey();
    $this->store->remember(Channel::Sms, $key, SMS_REQUEST, FIRST_RESPONSE);

    $result = $this->store->remember(Channel::Sms, $key, SMS_REQUEST, ['id' => 'second', 'status' => 'accepted']);

    expect($result->outcome)->toBe(IdempotencyOutcome::Replayed)
        ->and($result->response['id'])->toBe('first');
});

it('ignores the order of body fields', function (): void {
    $key = freshKey();
    $this->store->remember(Channel::Sms, $key, SMS_REQUEST, FIRST_RESPONSE);

    $result = $this->store->remember(Channel::Sms, $key, ['body' => 'Merhaba', 'to' => '+905000000001'], FIRST_RESPONSE);

    expect($result->outcome)->toBe(IdempotencyOutcome::Replayed);
});

it('conflicts when the same key carries a different body', function (): void {
    $key = freshKey();
    $this->store->remember(Channel::Sms, $key, SMS_REQUEST, FIRST_RESPONSE);

    $result = $this->store->remember(Channel::Sms, $key, ['to' => '+905000000001', 'body' => 'Başka'], FIRST_RESPONSE);

    expect($result->outcome)->toBe(IdempotencyOutcome::Conflict);
});

it('conflicts when the same key is used on another channel', function (): void {
    $key = freshKey();
    $this->store->remember(Channel::Sms, $key, SMS_REQUEST, FIRST_RESPONSE);

    $result = $this->store->remember(Channel::Email, $key, SMS_REQUEST, FIRST_RESPONSE);

    expect($result->outcome)->toBe(IdempotencyOutcome::Conflict);
});

it('makes a used key new again after forgetAll', function (): void {
    $key = freshKey();
    $this->store->remember(Channel::Sms, $key, SMS_REQUEST, FIRST_RESPONSE);

    $this->store->forgetAll();

    $result = $this->store->remember(Channel::Sms, $key, SMS_REQUEST, FIRST_RESPONSE);
    expect($result->outcome)->toBe(IdempotencyOutcome::Created);
});

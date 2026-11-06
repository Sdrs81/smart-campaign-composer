<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Models\Customer;

function customerWith(array $attributes): Customer
{
    return (new Customer)->forceFill($attributes);
}

it('can receive on a channel when opted in and the address exists', function (Channel $channel) {
    $customer = customerWith([
        'sms_opt_in' => true, 'email_opt_in' => true,
        'phone' => '+905000000001', 'email' => 'a@example.com',
    ]);

    expect($customer->canReceive($channel))->toBeTrue();
})->with([Channel::Sms, Channel::Email]);

it('cannot receive on a channel without opt-in even when the address exists', function (Channel $channel) {
    $customer = customerWith([
        'sms_opt_in' => false, 'email_opt_in' => false,
        'phone' => '+905000000001', 'email' => 'a@example.com',
    ]);

    expect($customer->canReceive($channel))->toBeFalse();
})->with([Channel::Sms, Channel::Email]);

it('cannot receive on a channel without an address even when opted in', function (Channel $channel) {
    $customer = customerWith([
        'sms_opt_in' => true, 'email_opt_in' => true,
        'phone' => null, 'email' => '',
    ]);

    expect($customer->canReceive($channel))->toBeFalse();
})->with([Channel::Sms, Channel::Email]);

it('checks each channel independently', function () {
    $customer = customerWith([
        'sms_opt_in' => true, 'email_opt_in' => false,
        'phone' => '+905000000001', 'email' => 'a@example.com',
    ]);

    expect($customer->canReceive(Channel::Sms))->toBeTrue()
        ->and($customer->canReceive(Channel::Email))->toBeFalse();
});

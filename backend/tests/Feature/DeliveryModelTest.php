<?php

declare(strict_types=1);

use App\Delivery\DeliveryErrorCode;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function sendingDelivery(?Campaign $campaign = null, ?Customer $customer = null, Channel $channel = Channel::Sms): Delivery
{
    $delivery = Delivery::create([
        'campaign_id' => ($campaign ?? Campaign::create(['brief' => 'Sepet terk']))->id,
        'customer_id' => ($customer ?? Customer::factory()->create())->id,
        'channel' => $channel,
        'idempotency_key' => hash('sha256', uniqid('', true)),
    ]);
    $delivery->forceFill(['status' => DeliveryStatus::Sending])->save();

    return $delivery;
}

it('starts pending with no attempts', function () {
    $delivery = sendingDelivery()->forceFill(['status' => DeliveryStatus::Pending]);
    $delivery->save();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->fresh()->attempts)->toBe(0);
});

it('stores the provider id and send time when marked sent', function () {
    $delivery = sendingDelivery();

    $delivery->markSent('msg-42');
    $delivery->save();

    $stored = $delivery->fresh();

    expect($stored->status)->toBe(DeliveryStatus::Sent)
        ->and($stored->provider_message_id)->toBe('msg-42')
        ->and($stored->sent_at)->not->toBeNull()
        ->and($stored->attempts)->toBe(1);
});

it('releases the claim and sets the next attempt when scheduling a retry', function () {
    $delivery = sendingDelivery();
    $delivery->forceFill(['claimed_at' => now()])->save();
    $retryAt = now()->addSeconds(5)->startOfSecond(); // timestamptz columns keep whole seconds

    $delivery->scheduleRetry($retryAt, DeliveryErrorCode::ProviderUnreachable, 'timeout');
    $delivery->save();

    $stored = $delivery->fresh();

    expect($stored->status)->toBe(DeliveryStatus::Pending)
        ->and($stored->claimed_at)->toBeNull()
        ->and($stored->next_attempt_at?->equalTo($retryAt))->toBeTrue()
        ->and($stored->last_error)->toBe('timeout')
        ->and($stored->last_error_code)->toBe(DeliveryErrorCode::ProviderUnreachable)
        ->and($stored->attempts)->toBe(1);
});

it('lets the database fill the timestamps of a raw snapshot insert', function () {
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    $customer = Customer::factory()->create();

    DB::insert(
        'INSERT INTO deliveries (campaign_id, customer_id, channel, idempotency_key) VALUES (?, ?, ?, ?)',
        [$campaign->id, $customer->id, 'sms', hash('sha256', 'raw')],
    );

    $stored = Delivery::firstOrFail();

    expect($stored->status)->toBe(DeliveryStatus::Pending)
        ->and($stored->created_at)->not->toBeNull()
        ->and($stored->updated_at)->not->toBeNull();
});

it('allows the same customer on another channel or campaign', function () {
    $campaign = Campaign::create(['brief' => 'A']);
    $customer = Customer::factory()->create();

    sendingDelivery($campaign, $customer, Channel::Sms);
    sendingDelivery($campaign, $customer, Channel::Email);
    sendingDelivery(Campaign::create(['brief' => 'B']), $customer, Channel::Sms);

    expect(Delivery::count())->toBe(3);
});

// Last on purpose: a unique violation aborts the surrounding Postgres test transaction.
it('rejects a second delivery for the same campaign, customer and channel', function () {
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    $customer = Customer::factory()->create();
    sendingDelivery($campaign, $customer);

    expect(fn () => sendingDelivery($campaign, $customer))->toThrow(UniqueConstraintViolationException::class);
});

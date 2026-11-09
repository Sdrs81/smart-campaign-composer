<?php

declare(strict_types=1);

use App\Delivery\MessageProvider;
use App\Delivery\RetryPolicy;
use App\Delivery\SendDeliveryJob;
use App\Delivery\SendResult;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function loggedDelivery(): Delivery
{
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending->value]);
    $campaign->messages()->create(['channel' => 'sms', 'subject' => null, 'body' => 'Merhaba!', 'source' => 'human']);

    return Delivery::create([
        'campaign_id' => $campaign->id,
        'customer_id' => Customer::factory()->create()->id,
        'channel' => Channel::Sms,
        'idempotency_key' => hash('sha256', uniqid('', true)),
    ]);
}

function sendLogged(Delivery $delivery, SendResult $answer): void
{
    app()->instance(MessageProvider::class, new class($answer) implements MessageProvider
    {
        public function __construct(private readonly SendResult $answer) {}

        public function send(Channel $channel, array $payload, string $idempotencyKey): SendResult
        {
            return $this->answer;
        }
    });
    app()->instance(RetryPolicy::class, new RetryPolicy(5, 200, 10000, fn (int $min, int $max): int => $max));

    app()->call([(new SendDeliveryJob($delivery->id))->withFakeQueueInteractions(), 'handle']);
}

it('logs a sent delivery with its campaign, delivery and attempt', function (): void {
    Log::spy();
    $delivery = loggedDelivery();

    sendLogged($delivery, SendResult::sent('msg-1'));

    Log::shouldHaveReceived('log')->once()->withArgs(fn (string $level, string $message, array $context): bool => $level === 'info'
        && $message === 'delivery.sent'
        && $context['campaign_id'] === $delivery->campaign_id
        && $context['delivery_id'] === $delivery->id
        && $context['attempt'] === 1
        && $context['channel'] === 'sms'
        && $context['reason'] === null);
});

it('logs a scheduled retry with the reason and a failed delivery as a warning', function (): void {
    Log::spy();
    $delivery = loggedDelivery();

    sendLogged($delivery, SendResult::transient('Provider answered 500'));
    // The released job runs after its delay; the second send only happens once the retry is due.
    DB::table('deliveries')->where('id', $delivery->id)->update(['next_attempt_at' => DB::raw("now() - interval '1 second'")]);
    sendLogged($delivery, SendResult::permanent('Provider answered 400'));

    Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'info'
        && $message === 'delivery.retry_scheduled'
        && $context['attempt'] === 1
        && $context['reason'] === 'Provider answered 500')->once();
    Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => $level === 'warning'
        && $message === 'delivery.failed'
        && $context['attempt'] === 2
        && $context['reason'] === 'The provider refused the message for good: Provider answered 400')->once();
});

it('never puts the recipient or the rendered text in the log context', function (): void {
    Log::spy();
    $delivery = loggedDelivery();
    $phone = (string) $delivery->customer->phone;

    sendLogged($delivery, SendResult::sent('msg-1'));

    Log::shouldHaveReceived('log')->withArgs(fn (string $level, string $message, array $context): bool => ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), $phone)
        && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'Merhaba'));
});

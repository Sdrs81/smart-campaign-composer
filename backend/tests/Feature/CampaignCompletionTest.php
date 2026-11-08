<?php

declare(strict_types=1);

use App\Delivery\AudienceSnapshotter;
use App\Delivery\MessageProvider;
use App\Delivery\RetryPolicy;
use App\Delivery\SendDeliveryJob;
use App\Delivery\SendResult;
use App\Delivery\StartCampaignJob;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function sendingCampaign(): Campaign
{
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending->value]);
    $campaign->messages()->create(['channel' => 'sms', 'subject' => null, 'body' => 'Merhaba!', 'source' => 'human']);

    return $campaign->fresh();
}

function deliveryIn(Campaign $campaign, string $status): Delivery
{
    $delivery = Delivery::create([
        'campaign_id' => $campaign->id,
        'customer_id' => Customer::factory()->create()->id,
        'channel' => Channel::Sms,
        'idempotency_key' => hash('sha256', uniqid('', true)),
    ]);
    Delivery::query()->whereKey($delivery->id)->update(['status' => $status, 'claimed_at' => $status === 'sending' ? now() : null]);

    return $delivery;
}

function runSend(Delivery $delivery, SendResult $answer): void
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

it('completes the campaign when the last delivery becomes final', function (string $finalStatus): void {
    $campaign = sendingCampaign();
    deliveryIn($campaign, 'sent');
    deliveryIn($campaign, $finalStatus);

    expect(Campaign::completeIfFinished($campaign->id))->toBeTrue()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
})->with(['sent', 'failed', 'skipped']);

it('leaves the campaign alone while a delivery is still pending or sending', function (string $openStatus): void {
    $campaign = sendingCampaign();
    deliveryIn($campaign, 'sent');
    deliveryIn($campaign, $openStatus);

    expect(Campaign::completeIfFinished($campaign->id))->toBeFalse()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
})->with(['pending', 'sending']);

it('completes only once', function (): void {
    $campaign = sendingCampaign();
    deliveryIn($campaign, 'sent');

    expect(Campaign::completeIfFinished($campaign->id))->toBeTrue()
        ->and(Campaign::completeIfFinished($campaign->id))->toBeFalse();
});

it('does not touch a campaign that is not sending', function (): void {
    $campaign = sendingCampaign();
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Approved->value]);
    deliveryIn($campaign, 'sent');

    expect(Campaign::completeIfFinished($campaign->id))->toBeFalse()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Approved);
});

it('completes after the last send job and not before', function (): void {
    $campaign = sendingCampaign();
    $first = deliveryIn($campaign, 'pending');
    $second = deliveryIn($campaign, 'pending');

    runSend($first, SendResult::sent('msg-1'));
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sending);

    runSend($second, SendResult::permanent('Provider answered 400: bad recipient'));
    expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
});

it('does not complete while a retry is scheduled', function (): void {
    $campaign = sendingCampaign();
    $only = deliveryIn($campaign, 'pending');

    runSend($only, SendResult::transient('Provider answered 500'));

    expect($only->fresh()->status->value)->toBe('pending')
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('repairs a campaign whose last delivery was saved but never completed it', function (): void {
    $campaign = sendingCampaign();
    $delivery = deliveryIn($campaign, 'sent');

    runSend($delivery, SendResult::sent('msg-1'));

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
});

it('completes a campaign whose frozen audience turned out empty', function (): void {
    Queue::fake();
    $campaign = Campaign::create(['brief' => 'Kimse yok']);
    $campaign->defineAudience(
        ['version' => 1, 'conditions' => [['field' => 'city', 'operator' => 'eq', 'value' => 'Ankara']]],
        [],
        ['channel_counts' => ['sms' => 0, 'email' => 0], 'unique_total' => 0, 'sample' => [], 'longest_values' => ['sms' => [], 'email' => []]],
    );
    $campaign->setContent();
    $campaign->approve();
    $campaign->save();
    Customer::factory()->create(['city' => 'Ankara', 'sms_opt_in' => false, 'email_opt_in' => false]);

    (new StartCampaignJob($campaign->id))->handle(app(AudienceSnapshotter::class));

    expect(Delivery::query()->count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Completed);
});

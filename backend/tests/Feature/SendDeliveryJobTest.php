<?php

declare(strict_types=1);

use App\Delivery\MessageProvider;
use App\Delivery\RetryPolicy;
use App\Delivery\SendDeliveryJob;
use App\Delivery\SendResult;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Enums\MessageSource;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** A provider that answers from a script and records every call, so the tests see what was sent. */
final class ScriptedProvider implements MessageProvider
{
    /** @var list<array{channel: Channel, payload: array<string, string>, key: string}> */
    public array $calls = [];

    /** @param  list<SendResult>  $script  answers in order; the last one repeats */
    public function __construct(private array $script) {}

    public function send(Channel $channel, array $payload, string $idempotencyKey): SendResult
    {
        $this->calls[] = ['channel' => $channel, 'payload' => $payload, 'key' => $idempotencyKey];

        return count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
    }
}

/** @param  list<SendResult>  $script */
function useProvider(array $script): ScriptedProvider
{
    $provider = new ScriptedProvider($script);
    app()->instance(MessageProvider::class, $provider);
    // Jitter pinned to its maximum so a delay can be compared with Retry-After.
    app()->instance(RetryPolicy::class, new RetryPolicy(5, 200, 10000, fn (int $min, int $max): int => $max));

    return $provider;
}

function pendingDelivery(array $customer = [], Channel $channel = Channel::Sms, string $body = 'Merhaba {{first_name}}!'): Delivery
{
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    CampaignMessage::create([
        'campaign_id' => $campaign->id,
        'channel' => $channel,
        'subject' => $channel === Channel::Email ? 'Konu {{first_name}}' : null,
        'body' => $body,
        'source' => MessageSource::Human,
    ]);

    return Delivery::create([
        'campaign_id' => $campaign->id,
        'customer_id' => Customer::factory()->create(['first_name' => 'Ayşe', ...$customer])->id,
        'channel' => $channel,
        'idempotency_key' => hash('sha256', uniqid('', true)),
    ]);
}

function runJob(Delivery $delivery): SendDeliveryJob
{
    $job = (new SendDeliveryJob($delivery->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('sends a pending delivery with the rendered text and its idempotency key', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery();

    runJob($delivery);

    $stored = $delivery->fresh();
    expect($stored->status)->toBe(DeliveryStatus::Sent)
        ->and($stored->provider_message_id)->toBe('msg-1')
        ->and($stored->attempts)->toBe(1)
        ->and($provider->calls)->toHaveCount(1)
        ->and($provider->calls[0]['key'])->toBe($delivery->idempotency_key)
        ->and($provider->calls[0]['payload'])->toBe(['to' => $delivery->customer->phone, 'body' => 'Merhaba Ayşe!']);
});

it('sends an email with its subject', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery(channel: Channel::Email);

    runJob($delivery);

    expect($provider->calls[0]['payload'])->toBe([
        'to' => $delivery->customer->email,
        'body' => 'Merhaba Ayşe!',
        'subject' => 'Konu Ayşe',
    ]);
});

it('retries a transient failure and then succeeds with the same key', function (): void {
    $provider = useProvider([SendResult::transient('Provider answered 500'), SendResult::sent('msg-2')]);
    $delivery = pendingDelivery();

    $first = runJob($delivery);

    $afterFirst = $delivery->fresh();
    expect($afterFirst->status)->toBe(DeliveryStatus::Pending)
        ->and($afterFirst->attempts)->toBe(1)
        ->and($afterFirst->claimed_at)->toBeNull()
        ->and($afterFirst->next_attempt_at)->not->toBeNull()
        ->and($afterFirst->last_error)->toContain('500');
    $first->assertReleased(delay: 1);

    runJob($delivery);

    $stored = $delivery->fresh();
    expect($stored->status)->toBe(DeliveryStatus::Sent)
        ->and($stored->attempts)->toBe(2)
        ->and($provider->calls)->toHaveCount(2)
        ->and($provider->calls[1]['key'])->toBe($provider->calls[0]['key']);
});

it('marks a permanent failure as failed without a retry', function (): void {
    useProvider([SendResult::permanent('Provider answered 400: bad recipient')]);
    $delivery = pendingDelivery();

    $job = runJob($delivery);

    $stored = $delivery->fresh();
    expect($stored->status)->toBe(DeliveryStatus::Failed)
        ->and($stored->attempts)->toBe(1)
        ->and($stored->last_error)->toContain('bad recipient');
    $job->assertNotReleased();
});

it('waits at least Retry-After after a 429', function (): void {
    useProvider([SendResult::rateLimited(3, 'Provider answered 429')]);
    $delivery = pendingDelivery();

    $job = runJob($delivery);

    $stored = $delivery->fresh();
    expect($stored->status)->toBe(DeliveryStatus::Pending)
        ->and($stored->next_attempt_at->getTimestamp() - now()->getTimestamp())->toBeGreaterThanOrEqual(2);
    $job->assertReleased(delay: 3);
});

it('fails the delivery once the retry policy gives up', function (): void {
    useProvider([SendResult::transient('Provider answered 500')]);
    $delivery = pendingDelivery();
    $delivery->forceFill(['attempts' => 4])->save();

    $job = runJob($delivery);

    $stored = $delivery->fresh();
    expect($stored->status)->toBe(DeliveryStatus::Failed)
        ->and($stored->attempts)->toBe(5)
        ->and($stored->last_error)->toContain('5 attempts');
    $job->assertNotReleased();
});

it('calls the provider once when the same delivery is queued twice', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery();

    runJob($delivery);
    runJob($delivery);

    expect($provider->calls)->toHaveCount(1)
        ->and($delivery->fresh()->attempts)->toBe(1);
});

it('does nothing when another worker holds a fresh claim', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery();
    DB::table('deliveries')->where('id', $delivery->id)->update(['status' => 'sending', 'claimed_at' => DB::raw('now()')]);

    runJob($delivery);

    expect($provider->calls)->toBeEmpty()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Sending);
});

it('takes over a claim whose worker died', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery();
    DB::table('deliveries')->where('id', $delivery->id)->update(['status' => 'sending', 'claimed_at' => DB::raw("now() - interval '2 minutes'")]);

    runJob($delivery);

    expect($provider->calls)->toHaveCount(1)
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Sent);
});

it('does not touch a delivery that is already final', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery();
    DB::table('deliveries')->where('id', $delivery->id)->update(['status' => 'failed']);

    runJob($delivery);

    expect($provider->calls)->toBeEmpty()
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::Failed);
});

it('skips a delivery whose consent was withdrawn after the audience was frozen', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery();
    $delivery->customer->update(['sms_opt_in' => false]);

    runJob($delivery);

    $stored = $delivery->fresh();
    expect($provider->calls)->toBeEmpty()
        ->and($stored->status)->toBe(DeliveryStatus::Skipped)
        ->and($stored->attempts)->toBe(0)
        ->and($stored->last_error)->toContain('no longer receive');
});

it('fails without calling the provider when the rendered text is too long', function (): void {
    $provider = useProvider([SendResult::sent('msg-1')]);
    $delivery = pendingDelivery(['first_name' => str_repeat('A', 200)]);

    runJob($delivery);

    $stored = $delivery->fresh();
    expect($provider->calls)->toBeEmpty()
        ->and($stored->status)->toBe(DeliveryStatus::Failed)
        ->and($stored->last_error)->toContain('limit is 160');
});

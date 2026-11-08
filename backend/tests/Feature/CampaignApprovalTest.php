<?php

declare(strict_types=1);

use App\Delivery\AudienceSnapshotter;
use App\Delivery\SendDeliveryJob;
use App\Delivery\StartCampaignJob;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use App\Segment\AudienceService;
use App\Segment\FieldRegistry;
use App\Segment\SegmentQueryCompiler;
use App\Segment\SegmentRuleParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/** Five Istanbul customers (SMS: 2, email: 3) with different consent, plus one in Ankara who is not in the rule. */
function seedAudience(): void
{
    Customer::factory()->create(['city' => 'İstanbul']);
    Customer::factory()->create(['city' => 'İstanbul', 'sms_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'email_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'sms_opt_in' => false, 'email_opt_in' => false]);
    Customer::factory()->create(['city' => 'İstanbul', 'phone' => null]);
    Customer::factory()->create(['city' => 'Ankara']);
}

function campaignInState(CampaignStatus $status = CampaignStatus::ContentReady, string $city = 'İstanbul'): Campaign
{
    $ruleArray = ['version' => 1, 'conditions' => [['field' => 'city', 'operator' => 'eq', 'value' => $city]]];
    $rule = (new SegmentRuleParser(new FieldRegistry(fn (): array => ['Ankara', 'İstanbul', 'Nowhere'])))->parse($ruleArray);
    $summary = (new AudienceService(new SegmentQueryCompiler))->summarize($rule);

    $campaign = Campaign::create(['brief' => 'İstanbul müşterilerine indirim']);
    $campaign->defineAudience($ruleArray, [], $summary->toArray());
    if ($status !== CampaignStatus::AudienceReady) {
        $campaign->setContent();
    }
    $campaign->save();
    $campaign->messages()->create(['channel' => 'sms', 'subject' => null, 'body' => 'Merhaba {{first_name}}!', 'source' => 'llm']);
    $campaign->messages()->create(['channel' => 'email', 'subject' => 'Sepetin bekliyor', 'body' => 'Merhaba {{first_name}}, sana özel indirim.', 'source' => 'llm']);

    return $campaign;
}

it('freezes exactly the counted audience, one row per channel and customer, with a stable key', function (): void {
    seedAudience();
    $campaign = campaignInState();
    $counts = $campaign->audience_counts['channel_counts'];

    $created = app(AudienceSnapshotter::class)->freeze($campaign);

    $perChannel = Delivery::query()->where('campaign_id', $campaign->id)->get()->countBy(fn (Delivery $delivery): string => $delivery->channel->value);
    expect($created)->toBe($counts['sms'] + $counts['email'])
        ->and($perChannel['sms'])->toBe($counts['sms'])
        ->and($perChannel['email'])->toBe($counts['email'])
        ->and($counts)->toBe(['sms' => 2, 'email' => 3]);

    $delivery = Delivery::query()->where('campaign_id', $campaign->id)->first();
    expect($delivery->status)->toBe(DeliveryStatus::Pending)
        ->and($delivery->idempotency_key)->toBe(hash('sha256', "{$campaign->id}:{$delivery->customer_id}:{$delivery->channel->value}"));
});

it('does not add rows when the audience is frozen again', function (): void {
    seedAudience();
    $campaign = campaignInState();
    $snapshotter = app(AudienceSnapshotter::class);

    $snapshotter->freeze($campaign);
    $again = $snapshotter->freeze($campaign);

    expect($again)->toBe(0)
        ->and(Delivery::query()->where('campaign_id', $campaign->id)->count())->toBe(5);
});

it('leaves out someone who withdrew consent after the audience was counted', function (): void {
    seedAudience();
    $campaign = campaignInState();
    Customer::query()->where('city', 'İstanbul')->orderBy('id')->first()->update(['sms_opt_in' => false]);

    app(AudienceSnapshotter::class)->freeze($campaign);

    expect(Delivery::query()->where('campaign_id', $campaign->id)->where('channel', 'sms')->count())->toBe(1);
});

it('approves once and starts one campaign job, however many times it is asked', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');
    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    Queue::assertPushed(StartCampaignJob::class, 1);
    expect(Delivery::query()->count())->toBe(0);
});

it('keeps an approved campaign as it is when approval is repeated after sending started', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();
    $this->postJson("/api/campaigns/{$campaign->id}/approve")->assertOk();
    (new StartCampaignJob($campaign->id))->handle(app(AudienceSnapshotter::class));
    $rows = Delivery::query()->count();

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'sending');

    Queue::assertPushed(StartCampaignJob::class, 1);
    expect(Delivery::query()->count())->toBe($rows);
});

it('answers 409 while another request holds the approval lock', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();
    config()->set('delivery.approval_lock_wait_seconds', 0);
    $heldElsewhere = Cache::lock("campaign:{$campaign->id}:approve", 10);
    expect($heldElsewhere->get())->toBeTrue();

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'approval_in_progress');

    Queue::assertNothingPushed();
    expect($campaign->fresh()->status)->toBe(CampaignStatus::ContentReady);
});

it('refuses an empty audience with 422', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState(city: 'Nowhere');

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'audience_empty');

    Queue::assertNothingPushed();
    expect($campaign->fresh()->status)->toBe(CampaignStatus::ContentReady);
});

it('refuses a campaign that is not content_ready with 409', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState(CampaignStatus::AudienceReady);

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_campaign_transition');

    Queue::assertNothingPushed();
});

it('refuses to approve a campaign that already failed instead of answering success', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Failed->value]);

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_campaign_transition');

    Queue::assertNothingPushed();
});

it('refuses text that no longer fits the limits', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();
    $campaign->messages()->where('channel', 'sms')->update(['body' => str_repeat('a', 161)]);

    $this->postJson("/api/campaigns/{$campaign->id}/approve")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['sms.body']]]]);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::ContentReady);
});

it('freezes the audience, starts sending and queues one send job per delivery on its channel queue', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();
    $campaign->approve();
    $campaign->save();

    (new StartCampaignJob($campaign->id))->handle(app(AudienceSnapshotter::class));

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Sending)
        ->and(Delivery::query()->count())->toBe(5);
    Queue::assertPushed(SendDeliveryJob::class, 5);
    Queue::assertPushedOn(Channel::Sms->value, SendDeliveryJob::class);
    Queue::assertPushedOn(Channel::Email->value, SendDeliveryJob::class);
});

it('adds no rows when the campaign job runs twice', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();
    $campaign->approve();
    $campaign->save();
    $job = new StartCampaignJob($campaign->id);

    $job->handle(app(AudienceSnapshotter::class));
    $job->handle(app(AudienceSnapshotter::class));

    expect(Delivery::query()->count())->toBe(5)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Sending);
});

it('does nothing for a campaign that was never approved', function (): void {
    Queue::fake();
    seedAudience();
    $campaign = campaignInState();

    (new StartCampaignJob($campaign->id))->handle(app(AudienceSnapshotter::class));

    expect(Delivery::query()->count())->toBe(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::ContentReady);
    Queue::assertNothingPushed();
});

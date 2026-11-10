<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Enums\MessageSource;
use App\Llm\Exceptions\LlmUnavailable;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use App\Segment\AudienceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function contentFake(): FakeLlmClient
{
    $fake = app(LlmClient::class);
    assert($fake instanceof FakeLlmClient);

    return $fake;
}

function contentAnswer(string $smsBody = 'Merhaba {{first_name}}, sepetin seni bekliyor!'): string
{
    return json_encode([
        'sms' => ['body' => $smsBody],
        'email' => ['subject' => 'Sepetin seni bekliyor', 'body' => 'Merhaba {{first_name}}, sepetindeki ürünler için küçük bir sürprizimiz var.'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function audienceReadyCampaign(): Campaign
{
    $campaign = Campaign::create(['brief' => 'Sepeti terk eden müşterilere indirim gönder']);
    $summary = new AudienceSummary(
        ['sms' => 10, 'email' => 12],
        14,
        [],
        ['sms' => ['first_name' => 8, 'last_name' => 10, 'city' => 9], 'email' => ['first_name' => 8, 'last_name' => 10, 'city' => 9]],
    );
    $campaign->defineAudience(['version' => 1, 'conditions' => []], [], $summary->toArray());
    $campaign->save();

    return $campaign;
}

/** @return list<array{event: string, data: array<string, mixed>}> */
function sseEvents(TestResponse $response): array
{
    $events = [];
    foreach (explode("\n\n", trim($response->streamedContent())) as $block) {
        preg_match('/^event: (\w+)\ndata: (.+)$/s', $block, $matches);
        $events[] = ['event' => $matches[1], 'data' => json_decode($matches[2], true, flags: JSON_THROW_ON_ERROR)];
    }

    return $events;
}

it('writes a different text when the content is generated again, with the dev scenarios on', function (): void {
    config(['llm.fake.dev_scenarios' => true]);
    Sleep::fake();
    $campaign = audienceReadyCampaign();
    $smsBody = fn (): ?string => CampaignMessage::query()->where('campaign_id', $campaign->id)->where('channel', Channel::Sms)->value('body');

    $this->postJson("/api/campaigns/{$campaign->id}/content/stream")->assertOk()->streamedContent();
    $first = $smsBody();
    $this->postJson("/api/campaigns/{$campaign->id}/content/stream")->assertOk()->streamedContent();
    $second = $smsBody();

    expect($first)->not->toBeNull()->and($second)->not->toBeNull()->and($second)->not->toBe($first);
});

it('streams deltas then a final event and stores the two generated messages', function (): void {
    contentFake()->enqueue(contentAnswer());
    $campaign = audienceReadyCampaign();

    $response = $this->postJson("/api/campaigns/{$campaign->id}/content/stream");

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($response->headers->get('X-Accel-Buffering'))->toBe('no');

    $events = sseEvents($response);
    $names = array_column($events, 'event');
    expect(count($events))->toBeGreaterThan(2)
        ->and(array_slice($names, 0, -1))->each->toBe('delta')
        ->and(end($names))->toBe('final')
        ->and(implode('', array_column(array_column(array_slice($events, 0, -1), 'data'), 'text')))->toBe(contentAnswer())
        ->and(end($events)['data']['repaired'])->toBeFalse()
        ->and(end($events)['data']['sms']['body'])->toContain('{{first_name}}');

    $campaign = $campaign->fresh();
    expect($campaign->status)->toBe(CampaignStatus::ContentReady)
        ->and($campaign->messages)->toHaveCount(2)
        ->and($campaign->messages->pluck('source')->unique()->all())->toBe([MessageSource::Llm]);
});

it('repairs an over-long SMS and reports it in the final event', function (): void {
    contentFake()->enqueue(contentAnswer(str_repeat('a', 161)), contentAnswer());
    $campaign = audienceReadyCampaign();

    $events = sseEvents($this->postJson("/api/campaigns/{$campaign->id}/content/stream"));

    expect(end($events)['event'])->toBe('final')
        ->and(end($events)['data']['repaired'])->toBeTrue();
});

it('sends an error event with the problem list and stores nothing when the text stays invalid', function (): void {
    contentFake()->enqueue(...array_fill(0, 3, contentAnswer(str_repeat('a', 161))));
    $campaign = audienceReadyCampaign();

    $events = sseEvents($this->postJson("/api/campaigns/{$campaign->id}/content/stream"));

    expect(end($events)['event'])->toBe('error')
        ->and(end($events)['data']['code'])->toBe('content_invalid')
        ->and(end($events)['data']['errors'][0])->toContain('sms.body');

    $campaign = $campaign->fresh();
    expect($campaign->status)->toBe(CampaignStatus::AudienceReady)
        ->and($campaign->messages)->toHaveCount(0);
});

it('sends an error event when the model is unreachable', function (): void {
    contentFake()->enqueue(new LlmUnavailable('Claude API is unreachable.'));
    $campaign = audienceReadyCampaign();

    $events = sseEvents($this->postJson("/api/campaigns/{$campaign->id}/content/stream"));

    expect($events)->toHaveCount(1)
        ->and($events[0]['event'])->toBe('error')
        ->and($events[0]['data']['code'])->toBe('llm_error');
});

it('replaces the messages when content is generated again', function (): void {
    contentFake()->enqueue(contentAnswer(), contentAnswer('Yeni metin {{first_name}}'));
    $campaign = audienceReadyCampaign();

    sseEvents($this->postJson("/api/campaigns/{$campaign->id}/content/stream"));
    sseEvents($this->postJson("/api/campaigns/{$campaign->id}/content/stream"));

    $messages = $campaign->fresh()->messages;
    expect($messages)->toHaveCount(2)
        ->and($messages->firstWhere('channel.value', 'sms')->body)->toBe('Yeni metin {{first_name}}');
});

it('refuses with 409 before streaming when there is no audience yet or the campaign is approved', function (): void {
    $draft = Campaign::create(['brief' => 'Sepeti terk eden müşterilere indirim gönder']);
    $this->postJson("/api/campaigns/{$draft->id}/content/stream")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_campaign_transition');

    $approved = audienceReadyCampaign();
    $approved->setContent();
    $approved->approve();
    $approved->save();
    $this->postJson("/api/campaigns/{$approved->id}/content/stream")->assertStatus(409);

    expect(contentFake()->requests())->toBeEmpty();
});

it('rejects a tone longer than the configured limit', function (): void {
    $campaign = audienceReadyCampaign();

    $this->postJson("/api/campaigns/{$campaign->id}/content/stream", ['tone' => str_repeat('a', config('llm.content.tone_max_length') + 1)])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['tone']]]]);
});

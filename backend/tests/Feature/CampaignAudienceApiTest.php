<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Llm\Exceptions\LlmUnavailable;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function fakeLlm(): FakeLlmClient
{
    $fake = app(LlmClient::class);
    assert($fake instanceof FakeLlmClient);

    return $fake;
}

function femaleSegmentAnswer(): string
{
    return json_encode([
        'rule' => ['version' => 1, 'conditions' => [['field' => 'gender', 'operator' => 'eq', 'value' => 'female']]],
        'unsupported_criteria' => ['iPhone kullananlar'],
        'explanation' => 'Kadın müşteriler.',
    ], JSON_THROW_ON_ERROR);
}

function draftCampaign(): Campaign
{
    return Campaign::create(['brief' => 'Kadın müşterilerimize özel bir kampanya']);
}

it('extracts the audience and stores the rule, unsupported criteria and opt-in counts', function (): void {
    Customer::factory()->create(['gender' => 'female']);
    Customer::factory()->create(['gender' => 'female', 'sms_opt_in' => false]);
    Customer::factory()->create(['gender' => 'male']);
    fakeLlm()->enqueue(femaleSegmentAnswer());
    $campaign = draftCampaign();

    $this->postJson("/api/campaigns/{$campaign->id}/audience")
        ->assertOk()
        ->assertJsonPath('data.status', 'audience_ready')
        ->assertJsonPath('data.segment_rule.conditions.0.field', 'gender')
        ->assertJsonPath('data.unsupported_criteria.0', 'iPhone kullananlar')
        ->assertJsonPath('data.audience_counts.channel_counts.sms', 1)
        ->assertJsonPath('data.audience_counts.channel_counts.email', 2)
        ->assertJsonPath('data.audience_counts.unique_total', 2);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::AudienceReady);
});

it('can be run again with a changed brief before approval', function (): void {
    fakeLlm()->enqueue(femaleSegmentAnswer(), femaleSegmentAnswer());
    $campaign = draftCampaign();

    $this->postJson("/api/campaigns/{$campaign->id}/audience")->assertOk();
    $this->postJson("/api/campaigns/{$campaign->id}/audience")->assertOk();
});

it('drops the saved messages when the audience is defined again', function (): void {
    fakeLlm()->enqueue(femaleSegmentAnswer());
    $campaign = draftCampaign();
    $campaign->defineAudience(['version' => 1, 'conditions' => []], [], []);
    $campaign->setContent();
    $campaign->save();
    $campaign->messages()->create(['channel' => 'sms', 'subject' => null, 'body' => 'Merhaba', 'source' => 'llm']);

    $this->postJson("/api/campaigns/{$campaign->id}/audience")->assertOk();

    expect($campaign->fresh()->messages)->toHaveCount(0)
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::AudienceReady);
});

it('answers 422 with the problem list when the model never produces a valid rule', function (): void {
    fakeLlm()->enqueue('bu bir JSON değil', 'bu da değil', 'yine değil');
    $campaign = draftCampaign();

    $this->postJson("/api/campaigns/{$campaign->id}/audience")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'segment_extraction_failed')
        ->assertJsonPath('error.details.attempts', 3)
        ->assertJsonPath('error.details.errors.0', fn (string $error): bool => str_contains($error, 'not valid JSON'));

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('answers 503 and keeps the campaign untouched when the model is unreachable', function (): void {
    fakeLlm()->enqueue(new LlmUnavailable('Claude API is unreachable.'));
    $campaign = draftCampaign();

    $this->postJson("/api/campaigns/{$campaign->id}/audience")
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'llm_unavailable');

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);
});

it('answers 409 for an approved campaign without calling the model', function (): void {
    $campaign = draftCampaign();
    $campaign->defineAudience(['version' => 1, 'conditions' => []], [], []);
    $campaign->setContent();
    $campaign->approve();
    $campaign->save();

    $this->postJson("/api/campaigns/{$campaign->id}/audience")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_campaign_transition');

    expect(fakeLlm()->requests())->toBeEmpty()
        ->and($campaign->fresh()->status)->toBe(CampaignStatus::Approved);
});

it('answers 404 for an unknown campaign', function (): void {
    $this->postJson('/api/campaigns/999999/audience')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

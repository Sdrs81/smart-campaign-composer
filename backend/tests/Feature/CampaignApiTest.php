<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects a too short brief with a field error', function () {
    $this->postJson('/api/campaigns', ['brief' => 'kısa'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['brief']]]]);
});

it('rejects a missing and an over-long brief', function () {
    $this->postJson('/api/campaigns', [])->assertStatus(422);
    $this->postJson('/api/campaigns', ['brief' => str_repeat('a', config('llm.brief.max_length') + 1)])->assertStatus(422);
});

it('creates a draft campaign and returns 201', function () {
    $this->postJson('/api/campaigns', ['brief' => 'Sepeti terk eden müşterilere indirim gönder'])
        ->assertCreated()
        ->assertJsonPath('data.status', CampaignStatus::Draft->value)
        ->assertJsonPath('data.brief', 'Sepeti terk eden müşterilere indirim gönder');

    expect(Campaign::count())->toBe(1);
});

it('ignores a client supplied status', function () {
    $this->postJson('/api/campaigns', ['brief' => 'Sepeti terk eden müşterilere indirim', 'status' => 'approved'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');
});

it('lists campaigns paginated, newest first', function () {
    foreach (range(1, 20) as $i) {
        Campaign::create(['brief' => "Kampanya brief numarası {$i}"]);
    }

    $this->getJson('/api/campaigns')
        ->assertOk()
        ->assertJsonCount(15, 'data')
        ->assertJsonPath('meta.total', 20)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.brief', 'Kampanya brief numarası 20');

    $this->getJson('/api/campaigns?page=2')->assertOk()->assertJsonCount(5, 'data');
});

it('shows a campaign with its messages', function () {
    $campaign = Campaign::create(['brief' => 'Sepeti terk eden müşterilere indirim']);
    $campaign->messages()->create(['channel' => 'sms', 'subject' => null, 'body' => 'Merhaba', 'source' => 'llm']);

    $this->getJson("/api/campaigns/{$campaign->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $campaign->id)
        ->assertJsonPath('data.messages.0.channel', 'sms')
        ->assertJsonPath('data.messages.0.body', 'Merhaba');
});

it('returns the 404 error shape for an unknown or malformed id', function () {
    $this->getJson('/api/campaigns/999999')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->getJson('/api/campaigns/abc')->assertNotFound()->assertJsonPath('error.code', 'not_found');
});

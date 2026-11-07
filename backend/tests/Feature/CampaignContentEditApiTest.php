<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\MessageSource;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function contentReadyCampaign(): Campaign
{
    $campaign = Campaign::create(['brief' => 'Sepeti terk eden müşterilere indirim gönder']);
    $longest = ['first_name' => 8, 'last_name' => 10, 'city' => 9];
    $campaign->defineAudience(['version' => 1, 'conditions' => []], [], [
        'channel_counts' => ['sms' => 10, 'email' => 12],
        'unique_total' => 14,
        'sample' => [['id' => 1, 'first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'city' => 'İzmir']],
        'longest_values' => ['sms' => $longest, 'email' => $longest],
    ]);
    $campaign->setContent();
    $campaign->save();
    $campaign->messages()->create(['channel' => 'sms', 'subject' => null, 'body' => 'Merhaba {{first_name}}!', 'source' => 'llm']);
    $campaign->messages()->create(['channel' => 'email', 'subject' => 'Sepetin bekliyor', 'body' => 'Merhaba {{first_name}}, sana özel indirim.', 'source' => 'llm']);

    return $campaign;
}

/** @return array<string, array<string, string>> */
function editedContent(string $smsBody = 'Merhaba {{first_name}}!'): array
{
    return [
        'sms' => ['body' => $smsBody],
        'email' => ['subject' => 'Yeni konu', 'body' => 'Merhaba {{first_name}}, sana özel indirim.'],
    ];
}

it('saves a valid human edit and marks only the changed message as human', function (): void {
    $campaign = contentReadyCampaign();

    $this->putJson("/api/campaigns/{$campaign->id}/content", editedContent())
        ->assertOk()
        ->assertJsonPath('data.status', 'content_ready');

    $messages = $campaign->fresh()->messages->keyBy(fn ($message) => $message->channel->value);
    expect($messages['sms']->source)->toBe(MessageSource::Llm)
        ->and($messages['email']->source)->toBe(MessageSource::Human)
        ->and($messages['email']->subject)->toBe('Yeni konu');
});

it('rejects a 161 character SMS with the path sms.body', function (): void {
    $campaign = contentReadyCampaign();

    $this->putJson("/api/campaigns/{$campaign->id}/content", editedContent(str_repeat('a', 161)))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['sms.body']]]])
        ->assertJsonMissingPath('error.details.errors.email.body');

    expect($campaign->fresh()->messages->firstWhere('channel.value', 'sms')->body)->toBe('Merhaba {{first_name}}!');
});

it('reports unknown variables and a missing field by path', function (): void {
    $campaign = contentReadyCampaign();

    $this->putJson("/api/campaigns/{$campaign->id}/content", ['sms' => ['body' => 'Selam {{phone}}'], 'email' => ['subject' => 'Konu']])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['email.body']]]]);

    $this->putJson("/api/campaigns/{$campaign->id}/content", editedContent('Selam {{phone}}'))
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['errors' => ['sms.body']]]]);
});

it('answers 409 when the campaign is not content_ready', function (CampaignStatus $status): void {
    $campaign = contentReadyCampaign();
    if ($status === CampaignStatus::Approved) {
        $campaign->approve();
        $campaign->save();
    } else {
        $campaign = Campaign::create(['brief' => 'Sepeti terk eden müşterilere indirim gönder']);
    }

    $this->putJson("/api/campaigns/{$campaign->id}/content", editedContent())
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'campaign_content_unavailable');
})->with([CampaignStatus::Approved, CampaignStatus::Draft]);

it('previews with the fallback for a customer without a first name', function (): void {
    $campaign = contentReadyCampaign();
    $customer = Customer::factory()->create(['first_name' => null]);

    $this->getJson("/api/campaigns/{$campaign->id}/preview?customer_id={$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.customer.id', $customer->id)
        ->assertJsonPath('data.messages.0.channel', 'sms')
        ->assertJsonPath('data.messages.0.body', 'Merhaba Değerli müşterimiz!')
        ->assertJsonPath('data.messages.0.lengths.body', mb_strlen('Merhaba Değerli müşterimiz!'));
});

it('previews a sample person when no customer is given', function (): void {
    $campaign = contentReadyCampaign();

    $this->getJson("/api/campaigns/{$campaign->id}/preview")
        ->assertOk()
        ->assertJsonPath('data.messages.0.body', 'Merhaba Ayşe!')
        ->assertJsonPath('data.messages.1.subject', 'Sepetin bekliyor');
});

it('answers 404 for an unknown customer, 422 for a bad id and 409 without content', function (): void {
    $campaign = contentReadyCampaign();

    $this->getJson("/api/campaigns/{$campaign->id}/preview?customer_id=999999")->assertNotFound();
    $this->getJson("/api/campaigns/{$campaign->id}/preview?customer_id=abc")->assertStatus(422);

    $empty = Campaign::create(['brief' => 'Sepeti terk eden müşterilere indirim gönder']);
    $this->getJson("/api/campaigns/{$empty->id}/preview")->assertStatus(409);
});

it('serves the limits and variables from the single config', function (): void {
    $this->getJson('/api/content/rules')
        ->assertOk()
        ->assertJsonPath('data.limits', config('delivery.limits'))
        ->assertJsonPath('data.channels', ['sms', 'email'])
        ->assertJsonPath('data.variables.0', ['name' => 'first_name', 'fallback' => 'Değerli müşterimiz']);
});

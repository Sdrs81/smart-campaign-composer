<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\MessageSource;
use App\Models\Campaign;
use App\Models\CampaignMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function messageFor(Campaign $campaign, Channel $channel, string $body = 'Merhaba'): CampaignMessage
{
    return CampaignMessage::create([
        'campaign_id' => $campaign->id,
        'channel' => $channel,
        'subject' => $channel === Channel::Email ? 'Konu' : null,
        'body' => $body,
        'source' => MessageSource::Llm,
    ]);
}

it('stores one message per channel with casts', function () {
    $campaign = Campaign::create(['brief' => 'Sepet terk']);

    messageFor($campaign, Channel::Sms);
    messageFor($campaign, Channel::Email);

    $sms = CampaignMessage::where('channel', 'sms')->firstOrFail();

    expect(CampaignMessage::count())->toBe(2)
        ->and($sms->channel)->toBe(Channel::Sms)
        ->and($sms->source)->toBe(MessageSource::Llm)
        ->and($sms->subject)->toBeNull()
        ->and($sms->campaign->is($campaign))->toBeTrue();
});

it('allows the same channel on another campaign', function () {
    messageFor(Campaign::create(['brief' => 'A']), Channel::Sms);
    messageFor(Campaign::create(['brief' => 'B']), Channel::Sms);

    expect(CampaignMessage::count())->toBe(2);
});

// Last on purpose: a unique violation aborts the surrounding Postgres test transaction.
it('rejects a second message for the same campaign and channel', function () {
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    messageFor($campaign, Channel::Sms);

    expect(fn () => messageFor($campaign, Channel::Sms, 'İkinci'))->toThrow(UniqueConstraintViolationException::class);
});

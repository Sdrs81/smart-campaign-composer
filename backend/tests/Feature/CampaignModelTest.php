<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts as a draft and round-trips the jsonb columns', function () {
    $campaign = Campaign::create(['brief' => 'Sepeti terk edenlere indirim']);

    expect($campaign->fresh()->status)->toBe(CampaignStatus::Draft);

    $campaign->defineAudience(['field' => 'city'], ['iPhone users'], ['sms' => 10, 'email' => 12]);
    $campaign->save();

    $stored = $campaign->fresh();

    expect($stored->status)->toBe(CampaignStatus::AudienceReady)
        ->and($stored->segment_rule)->toBe(['field' => 'city'])
        ->and($stored->unsupported_criteria)->toBe(['iPhone users'])
        ->and($stored->audience_counts)->toBe(['sms' => 10, 'email' => 12]);
});

<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Exceptions\InvalidCampaignTransition;
use App\Models\Campaign;

/**
 * Each model method with the states it may start from and the state it ends in. The allowed
 * pairs below are derived from this one table, so the forbidden ones are everything else.
 *
 * @return array<string, array{from: list<CampaignStatus>, to: CampaignStatus}>
 */
function campaignTransitions(): array
{
    return [
        'defineAudience' => ['from' => [CampaignStatus::Draft, CampaignStatus::AudienceReady, CampaignStatus::ContentReady], 'to' => CampaignStatus::AudienceReady],
        'setContent' => ['from' => [CampaignStatus::AudienceReady, CampaignStatus::ContentReady], 'to' => CampaignStatus::ContentReady],
        'approve' => ['from' => [CampaignStatus::ContentReady, CampaignStatus::Approved], 'to' => CampaignStatus::Approved],
        'startSending' => ['from' => [CampaignStatus::Approved], 'to' => CampaignStatus::Sending],
        'complete' => ['from' => [CampaignStatus::Sending], 'to' => CampaignStatus::Completed],
        'fail' => ['from' => [CampaignStatus::Sending], 'to' => CampaignStatus::Failed],
    ];
}

function campaignIn(CampaignStatus $status): Campaign
{
    return (new Campaign)->forceFill(['status' => $status]);
}

function callTransition(Campaign $campaign, string $method): void
{
    $method === 'defineAudience'
        ? $campaign->defineAudience(['all' => []], ['iPhone users'], ['sms' => 1])
        : $campaign->{$method}();
}

dataset('method and starting status', function () {
    foreach (campaignTransitions() as $method => $rule) {
        foreach (CampaignStatus::cases() as $status) {
            yield "{$method} from {$status->value}" => [$method, $status, in_array($status, $rule['from'], true)];
        }
    }
});

dataset('status pair', function () {
    foreach (CampaignStatus::cases() as $from) {
        foreach (CampaignStatus::cases() as $to) {
            $allowed = array_filter(
                campaignTransitions(),
                fn (array $rule): bool => $rule['to'] === $to && in_array($from, $rule['from'], true),
            ) !== [];

            yield "{$from->value} to {$to->value}" => [$from, $to, $allowed];
        }
    }
});

it('moves through the model methods only along allowed transitions', function (string $method, CampaignStatus $from, bool $allowed) {
    $campaign = campaignIn($from);

    if ($allowed) {
        callTransition($campaign, $method);

        expect($campaign->status)->toBe(campaignTransitions()[$method]['to']);

        return;
    }

    expect(fn () => callTransition($campaign, $method))->toThrow(InvalidCampaignTransition::class)
        ->and($campaign->status)->toBe($from);
})->with('method and starting status');

it('knows every allowed and forbidden status pair', function (CampaignStatus $from, CampaignStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with('status pair');

it('stores the audience only when the transition is allowed', function () {
    $campaign = campaignIn(CampaignStatus::Approved);

    expect(fn () => $campaign->defineAudience(['all' => []], [], ['sms' => 1]))->toThrow(InvalidCampaignTransition::class)
        ->and($campaign->segment_rule)->toBeNull();

    $draft = campaignIn(CampaignStatus::Draft);
    $draft->defineAudience(['all' => []], ['iPhone users'], ['sms' => 1]);

    expect($draft->segment_rule)->toBe(['all' => []])
        ->and($draft->unsupported_criteria)->toBe(['iPhone users'])
        ->and($draft->audience_counts)->toBe(['sms' => 1]);
});

it('names the states and the allowed next ones in the error', function () {
    $exception = InvalidCampaignTransition::between(CampaignStatus::Draft, CampaignStatus::Approved);

    expect($exception->getMessage())->toBe("Campaign cannot move from 'draft' to 'approved'; allowed next states: audience_ready.")
        ->and($exception->details())->toBe(['from' => 'draft', 'to' => 'approved', 'allowed' => ['audience_ready']])
        ->and($exception->status())->toBe(409)
        ->and($exception->errorCode())->toBe('invalid_campaign_transition');
});

it('does not let mass assignment set the status', function () {
    $campaign = new Campaign(['brief' => 'Sepet terk', 'status' => CampaignStatus::Completed]);

    expect($campaign->status)->toBe(CampaignStatus::Draft);
});

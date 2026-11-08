<?php

declare(strict_types=1);

use App\Delivery\CampaignStats;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $columns
 */
function statsDelivery(Campaign $campaign, string $channel, string $status, array $columns = []): Delivery
{
    $delivery = Delivery::create([
        'campaign_id' => $campaign->id,
        'customer_id' => Customer::factory()->create()->id,
        'channel' => $channel,
        'idempotency_key' => hash('sha256', uniqid('', true)),
    ]);
    // Status and outcome columns are not fillable on purpose, so fixtures write them directly.
    Delivery::query()->whereKey($delivery->id)->update(['status' => $status] + $columns);

    return $delivery;
}

function statsCampaign(): Campaign
{
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Sending->value]);

    return $campaign->fresh();
}

it('reports delivery counts per channel and status exactly', function (): void {
    $campaign = statsCampaign();
    $otherCampaign = statsCampaign();

    foreach (['sent' => 3, 'failed' => 1, 'pending' => 2] as $status => $count) {
        for ($i = 0; $i < $count; $i++) {
            statsDelivery($campaign, 'sms', $status);
        }
    }
    statsDelivery($campaign, 'email', 'sent');
    statsDelivery($campaign, 'email', 'skipped');
    statsDelivery($otherCampaign, 'sms', 'sent');

    $response = $this->getJson("/api/campaigns/{$campaign->id}/stats")->assertOk();

    expect($response->json('data.campaign_id'))->toBe($campaign->id)
        ->and($response->json('data.status'))->toBe('sending')
        ->and($response->json('data.channels.sms'))->toBe([
            'total' => 6, 'pending' => 2, 'sending' => 0, 'sent' => 3, 'failed' => 1, 'skipped' => 0,
        ])
        ->and($response->json('data.channels.email'))->toBe([
            'total' => 2, 'pending' => 0, 'sending' => 0, 'sent' => 1, 'failed' => 0, 'skipped' => 1,
        ]);
});

it('returns zero counts for a campaign without deliveries', function (): void {
    $campaign = statsCampaign();

    $response = $this->getJson("/api/campaigns/{$campaign->id}/stats")->assertOk();

    expect($response->json('data.channels.sms.total'))->toBe(0)
        ->and($response->json('data.send_rate_per_second'))->toBe(0)
        ->and($response->json('data.total_retries'))->toBe(0)
        ->and($response->json('data.recent_errors'))->toBe([]);
});

it('computes the send rate over the last five seconds only', function (): void {
    $campaign = statsCampaign();
    for ($i = 0; $i < 10; $i++) {
        statsDelivery($campaign, 'sms', 'sent', ['sent_at' => now()->subSeconds(2), 'attempts' => 1]);
    }
    statsDelivery($campaign, 'sms', 'sent', ['sent_at' => now()->subSeconds(60), 'attempts' => 1]);

    $rate = $this->getJson("/api/campaigns/{$campaign->id}/stats")->json('data.send_rate_per_second');

    expect($rate)->toBe(10 / CampaignStats::RATE_WINDOW_SECONDS);
});

it('counts only attempts after the first as retries', function (): void {
    $campaign = statsCampaign();
    statsDelivery($campaign, 'sms', 'sent', ['attempts' => 3]);   // 2 retries
    statsDelivery($campaign, 'sms', 'failed', ['attempts' => 5]); // 4 retries
    statsDelivery($campaign, 'sms', 'sent', ['attempts' => 1]);   // 0 retries
    statsDelivery($campaign, 'sms', 'pending', ['attempts' => 2]); // 2 scheduled retries
    statsDelivery($campaign, 'sms', 'skipped', ['attempts' => 0]);

    $this->getJson("/api/campaigns/{$campaign->id}/stats")
        ->assertOk()
        ->assertJsonPath('data.total_retries', 8);
});

it('lists the ten most recent errors without customer data', function (): void {
    $campaign = statsCampaign();
    for ($i = 1; $i <= 12; $i++) {
        $delivery = statsDelivery($campaign, 'sms', 'failed', ['last_error' => "error {$i}", 'attempts' => 1]);
        DB::table('deliveries')->where('id', $delivery->id)->update(['updated_at' => now()->subMinutes(20 - $i)]);
    }
    statsDelivery($campaign, 'sms', 'sent', ['attempts' => 1]);

    $errors = $this->getJson("/api/campaigns/{$campaign->id}/stats")->json('data.recent_errors');

    expect($errors)->toHaveCount(CampaignStats::RECENT_ERROR_LIMIT)
        ->and($errors[0]['error'])->toBe('error 12')
        ->and($errors[9]['error'])->toBe('error 3')
        ->and(array_keys($errors[0]))->toBe(['delivery_id', 'channel', 'status', 'attempts', 'error', 'at']);
});

it('keeps the error of a retried delivery that later succeeded and shows its status', function (): void {
    $campaign = statsCampaign();
    statsDelivery($campaign, 'email', 'sent', ['last_error' => 'Provider answered 500', 'attempts' => 3]);

    $errors = $this->getJson("/api/campaigns/{$campaign->id}/stats")->json('data.recent_errors');

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['status'])->toBe('sent')
        ->and($errors[0]['error'])->toBe('Provider answered 500');
});

it('masks a rejected recipient that the provider error echoes', function (): void {
    $campaign = statsCampaign();
    statsDelivery($campaign, 'sms', 'failed', [
        'last_error' => "Provider answered 400: '+905001234567' is not a valid sms recipient (ayse@example.com).",
        'attempts' => 1,
    ]);

    $response = $this->getJson("/api/campaigns/{$campaign->id}/stats")->assertOk();

    expect($response->json('data.recent_errors.0.error'))
        ->toBe("Provider answered 400: '[REDACTED]' is not a valid sms recipient ([REDACTED]).");
});

it('answers 404 for an unknown campaign', function (): void {
    $this->getJson('/api/campaigns/999999/stats')->assertNotFound();
});

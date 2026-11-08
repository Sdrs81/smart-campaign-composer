<?php

declare(strict_types=1);

use App\Delivery\CampaignStats;
use App\Delivery\CampaignStatsStream;
use App\Enums\CampaignStatus;
use App\Enums\Channel;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Delivery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function streamCampaign(CampaignStatus $status): Campaign
{
    $campaign = Campaign::create(['brief' => 'Sepet terk']);
    Campaign::query()->whereKey($campaign->id)->update(['status' => $status->value]);

    return $campaign->fresh();
}

/**
 * Runs the stream with a fake clock: every "sleep" moves time forward and lets the test change the data.
 *
 * @param  Closure(int): void|null  $onSleep  Receives the 1-based sleep number.
 * @return list<string>
 */
function collectFrames(Campaign $campaign, ?Closure $onSleep = null): array
{
    Carbon::setTestNow(now());
    $sleeps = 0;
    $stream = new CampaignStatsStream(app(CampaignStats::class), function (int $seconds) use (&$sleeps, $onSleep): void {
        $sleeps++;
        Carbon::setTestNow(now()->addSeconds($seconds));
        if ($onSleep !== null) {
            $onSleep($sleeps);
        }
    });

    return iterator_to_array($stream($campaign), preserve_keys: false);
}

function framesOfType(array $frames, string $prefix): int
{
    return count(array_filter($frames, static fn (string $frame): bool => str_starts_with($frame, $prefix)));
}

it('sends one snapshot and closes for a completed campaign', function (): void {
    $campaign = streamCampaign(CampaignStatus::Completed);

    $response = $this->get("/api/campaigns/{$campaign->id}/stats/stream")->assertOk();
    $content = $response->streamedContent();

    expect($response->headers->get('Content-Type'))->toStartWith('text/event-stream')
        ->and($content)->toStartWith("retry: 3000\n\n")
        ->and(substr_count($content, 'event: stats'))->toBe(1)
        ->and($content)->toContain('"status":"completed"')
        // The client needs an explicit end, or EventSource would reconnect to a finished campaign forever.
        ->and($content)->toEndWith("event: end\ndata: {}\n\n");
});

it('sends a snapshot only when the numbers change and ends on the final status', function (): void {
    $campaign = streamCampaign(CampaignStatus::Sending);

    $frames = collectFrames($campaign, function (int $sleep) use ($campaign): void {
        if ($sleep === 2) {
            Delivery::create([
                'campaign_id' => $campaign->id,
                'customer_id' => Customer::factory()->create()->id,
                'channel' => Channel::Sms,
                'idempotency_key' => hash('sha256', 'stream-test'),
            ]);
        }
        if ($sleep === 4) {
            Campaign::query()->whereKey($campaign->id)->update(['status' => CampaignStatus::Completed->value]);
        }
    });

    // Unchanged polls (sleeps 1 and 3) send nothing: first snapshot, the new delivery, the final status.
    expect($frames)->toHaveCount(5)
        ->and($frames[0])->toBe("retry: 3000\n\n")
        ->and(framesOfType($frames, 'event: stats'))->toBe(3)
        ->and($frames[1])->toContain('"total":0')
        ->and($frames[2])->toContain('"pending":1')
        ->and($frames[3])->toContain('"status":"completed"')
        ->and($frames[4])->toBe("event: end\ndata: {}\n\n");
});

it('sends a heartbeat comment after fifteen quiet seconds', function (): void {
    $campaign = streamCampaign(CampaignStatus::Sending);
    Carbon::setTestNow(now());
    $startedAt = now();
    $stream = new CampaignStatsStream(app(CampaignStats::class), fn (int $seconds) => Carbon::setTestNow(now()->addSeconds($seconds)));

    $received = [];
    foreach ($stream($campaign) as $frame) {
        $received[] = $frame;
        if ($frame === ": heartbeat\n\n") {
            break;
        }
    }

    expect($received)->toHaveCount(3)
        ->and(framesOfType($received, 'event: stats'))->toBe(1)
        ->and($startedAt->diffInSeconds(now(), absolute: true))->toBe((float) CampaignStatsStream::HEARTBEAT_SECONDS);
});

it('closes a stream that has run for ten minutes', function (): void {
    $campaign = streamCampaign(CampaignStatus::Sending);
    Carbon::setTestNow(now());
    $startedAt = now();

    $frames = collectFrames($campaign);

    expect(framesOfType($frames, 'event: stats'))->toBe(1)
        // An old stream just closes: the client reconnects and gets a fresh snapshot.
        ->and(framesOfType($frames, 'event: end'))->toBe(0)
        ->and(framesOfType($frames, ': heartbeat'))->toBe(intdiv(CampaignStatsStream::MAX_SECONDS, CampaignStatsStream::HEARTBEAT_SECONDS))
        ->and($startedAt->diffInSeconds(now(), absolute: true))->toBeGreaterThanOrEqual(CampaignStatsStream::MAX_SECONDS);
});

it('answers 404 for an unknown campaign stream', function (): void {
    $this->getJson('/api/campaigns/999999/stats/stream')->assertNotFound();
});

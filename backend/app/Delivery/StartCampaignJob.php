<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\CampaignStatus;
use App\Enums\DeliveryStatus;
use App\Models\Campaign;
use App\Models\Delivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Freezes the audience and hands one SendDeliveryJob per delivery to the channel's queue (K16).
 * Safe to run again: the snapshot ignores existing rows, and a repeated send job exits on a final row.
 */
class StartCampaignJob implements ShouldQueue
{
    use Queueable;

    private const DISPATCH_CHUNK_SIZE = 1000;

    public function __construct(public readonly int $campaignId) {}

    public function handle(AudienceSnapshotter $snapshotter): void
    {
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return;
        }

        if ($campaign->status === CampaignStatus::Approved) {
            $snapshotter->freeze($campaign);
            $campaign->startSending();
            $campaign->save();
        } elseif ($campaign->status !== CampaignStatus::Sending) {
            // Already finished, or never approved: nothing to start.
            return;
        }

        $this->dispatchPendingDeliveries($campaign);
    }

    /** Streams ids in chunks, so memory stays flat however large the audience is. */
    private function dispatchPendingDeliveries(Campaign $campaign): void
    {
        Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Pending->value)
            ->lazyById(self::DISPATCH_CHUNK_SIZE)
            ->each(fn (Delivery $delivery) => SendDeliveryJob::dispatch($delivery->id)->onQueue($delivery->channel->value));
    }
}

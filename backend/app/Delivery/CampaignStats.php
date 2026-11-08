<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use App\Enums\DeliveryStatus;
use App\Logging\SensitiveText;
use App\Models\Campaign;
use App\Models\Delivery;

/**
 * Read-only progress snapshot of one campaign's deliveries. Counts come from a single
 * GROUP BY that the (campaign_id, channel, status) index serves.
 */
class CampaignStats
{
    public const RATE_WINDOW_SECONDS = 5;

    public const RECENT_ERROR_LIMIT = 10;

    /**
     * @return array{
     *     campaign_id: int,
     *     status: string,
     *     channels: array<string, array<string, int>>,
     *     send_rate_per_second: float,
     *     total_retries: int,
     *     recent_errors: list<array<string, mixed>>
     * }
     */
    public function __invoke(Campaign $campaign): array
    {
        $groups = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->selectRaw('channel, status, COUNT(*) AS deliveries, SUM(attempts) AS attempts')
            ->groupBy('channel', 'status')
            ->toBase()
            ->get();

        $channels = $this->emptyChannelCounts();
        $attempts = 0;
        $finishedWithProviderAttempt = 0;

        foreach ($groups as $group) {
            $count = (int) $group->deliveries;
            $channels[$group->channel][$group->status] = $count;
            $channels[$group->channel]['total'] += $count;
            $attempts += (int) $group->attempts;

            // The last attempt of a sent or failed delivery is not a retry; every earlier one is.
            if (in_array($group->status, [DeliveryStatus::Sent->value, DeliveryStatus::Failed->value], true)) {
                $finishedWithProviderAttempt += $count;
            }
        }

        return [
            'campaign_id' => $campaign->id,
            'status' => $campaign->status->value,
            'channels' => $channels,
            'send_rate_per_second' => $this->sendRate($campaign),
            'total_retries' => $attempts - $finishedWithProviderAttempt,
            'recent_errors' => $this->recentErrors($campaign),
        ];
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function emptyChannelCounts(): array
    {
        $statusCounts = ['total' => 0];
        foreach (DeliveryStatus::cases() as $status) {
            $statusCounts[$status->value] = 0;
        }

        $channels = [];
        foreach (Channel::cases() as $channel) {
            $channels[$channel->value] = $statusCounts;
        }

        return $channels;
    }

    private function sendRate(Campaign $campaign): float
    {
        $sentInWindow = Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', DeliveryStatus::Sent->value)
            ->where('sent_at', '>=', now()->subSeconds(self::RATE_WINDOW_SECONDS))
            ->count();

        return $sentInWindow / self::RATE_WINDOW_SECONDS;
    }

    /**
     * A delivery that failed once and later succeeded keeps its last_error, so retried errors are
     * listed too; the status field tells the reader whether the delivery recovered.
     * No customer columns on purpose: the error text and delivery id are enough to investigate.
     *
     * @return list<array<string, mixed>>
     */
    private function recentErrors(Campaign $campaign): array
    {
        return Delivery::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('last_error')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_ERROR_LIMIT)
            ->get(['id', 'channel', 'status', 'attempts', 'last_error', 'updated_at'])
            ->map(fn (Delivery $delivery): array => [
                'delivery_id' => $delivery->id,
                'channel' => $delivery->channel->value,
                'status' => $delivery->status->value,
                'attempts' => $delivery->attempts,
                // Provider error bodies can echo the rejected recipient; the API must not hand it out.
                'error' => SensitiveText::mask((string) $delivery->last_error),
                'at' => $delivery->updated_at,
            ])
            ->all();
    }
}

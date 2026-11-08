<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use Illuminate\Support\Facades\Queue;

/** The queues the app uses and how many jobs each holds; one list for health and metrics. */
class QueueDepths
{
    // The queue backend is Redis in every environment; the test default (sync) cannot report a depth.
    private const QUEUE_CONNECTION = 'redis';

    // Campaign start jobs run on the default queue; deliveries run on the queue named after their channel.
    private const CAMPAIGN_QUEUE = 'default';

    /**
     * Jobs waiting, delayed or being worked, by queue name.
     *
     * @return array<string, int>
     */
    public function __invoke(): array
    {
        $queues = [self::CAMPAIGN_QUEUE, ...array_map(static fn (Channel $channel): string => $channel->value, Channel::cases())];

        $depths = [];
        foreach ($queues as $queue) {
            $depths[$queue] = Queue::connection(self::QUEUE_CONNECTION)->size($queue);
        }

        return $depths;
    }
}

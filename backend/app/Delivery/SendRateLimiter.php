<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;

/**
 * The per-channel outbound limit a send waits on. The send job depends on this interface so its tests can
 * use a limiter whose state does not hang on the clock; ChannelRateLimiter is the Redis implementation.
 */
interface SendRateLimiter
{
    /**
     * Returns once this caller may send one message on the channel.
     *
     * @throws ChannelRateLimitTimeout when no slot frees up within the limiter's wait
     */
    public function await(Channel $channel): void;
}

<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;
use RuntimeException;

final class ChannelRateLimitTimeout extends RuntimeException
{
    public static function waitingFor(Channel $channel, int $seconds): self
    {
        return new self("Waited {$seconds} seconds for a free {$channel->value} send slot and got none; the channel is saturated.");
    }
}

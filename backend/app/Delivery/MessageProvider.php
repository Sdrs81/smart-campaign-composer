<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Enums\Channel;

interface MessageProvider
{
    /**
     * Sends one message. Provider and network failures do not throw: they come back as a
     * classified result, so the caller only decides what to do next.
     *
     * @param  array<string, string>  $payload  "to" and "body", plus "subject" for email
     * @param  string  $idempotencyKey  the delivery's key, so a retry after a timeout is not sent twice
     */
    public function send(Channel $channel, array $payload, string $idempotencyKey): SendResult;
}

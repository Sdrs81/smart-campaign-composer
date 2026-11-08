<?php

declare(strict_types=1);

namespace App\Delivery;

/**
 * How the provider answered, reduced to what the retry policy needs to decide.
 */
enum SendOutcome: string
{
    case Sent = 'sent';
    case RateLimited = 'rate_limited';
    case Transient = 'transient';
    case Permanent = 'permanent';
}

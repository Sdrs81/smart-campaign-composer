<?php

declare(strict_types=1);

namespace MockProvider;

/** Why a request got 429, kept apart in /stats: only `Limit` means the sender went over the limit (K17). */
enum RateLimitCause: string
{
    case Limit = 'limit';
    case Injected = 'injected';
}

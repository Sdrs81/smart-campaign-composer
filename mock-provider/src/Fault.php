<?php

declare(strict_types=1);

namespace MockProvider;

/**
 * The backing value is also what a test sends in the X-Mock-Fault header.
 */
enum Fault: string
{
    case None = 'none';
    case ServerError = '500';
    case RateLimited = '429';
    case Timeout = 'timeout';
    // The provider keeps the message, then answers 500: the retry must not send it twice.
    case AcceptThenServerError = 'accept-then-500';
}

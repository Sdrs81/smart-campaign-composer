<?php

declare(strict_types=1);

namespace App\Delivery;

use Closure;
use InvalidArgumentException;

/**
 * Decides whether and when a failed send is tried again. Pure: the only outside input
 * is the random source, so tests can pin the jitter.
 */
final readonly class RetryPolicy
{
    private const MILLISECONDS_PER_SECOND = 1000;

    /**
     * @param  Closure(int, int): int  $random  returns an integer in [min, max], both inclusive
     */
    public function __construct(
        private int $maxAttempts,
        private int $baseDelayMilliseconds,
        private int $maxDelayMilliseconds,
        private Closure $random,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('delivery.retry.max_attempts'),
            (int) config('delivery.retry.base_delay_ms'),
            (int) config('delivery.retry.max_delay_ms'),
            random_int(...),
        );
    }

    /**
     * @param  int  $finishedAttempts  provider attempts made so far, including the one that just failed (1 after the first)
     */
    public function decide(SendResult $result, int $finishedAttempts): RetryDecision
    {
        return match ($result->outcome) {
            SendOutcome::Sent => throw new InvalidArgumentException('A sent message needs no retry decision; decide() takes a failed result.'),
            SendOutcome::Permanent => RetryDecision::giveUp('The provider refused the message for good: '.$result->reason),
            SendOutcome::RateLimited, SendOutcome::Transient => $this->retryOrGiveUp($result, $finishedAttempts),
        };
    }

    private function retryOrGiveUp(SendResult $result, int $finishedAttempts): RetryDecision
    {
        if ($finishedAttempts >= $this->maxAttempts) {
            return RetryDecision::giveUp("Gave up after {$finishedAttempts} attempts: {$result->reason}");
        }

        $backoff = $this->backoffMilliseconds($finishedAttempts);

        if ($result->retryAfterSeconds === null) {
            return RetryDecision::retryIn($backoff);
        }

        // The provider's own wait is a floor: coming back earlier would only be refused again.
        return RetryDecision::retryIn(max($result->retryAfterSeconds * self::MILLISECONDS_PER_SECOND, $backoff));
    }

    /**
     * Full jitter: a random wait between 0 and min(cap, base · 2^(n-1)), so workers that failed
     * together do not come back together.
     */
    private function backoffMilliseconds(int $finishedAttempts): int
    {
        $exponent = min($finishedAttempts - 1, $this->maxAttempts);
        $ceiling = min($this->maxDelayMilliseconds, $this->baseDelayMilliseconds * 2 ** $exponent);

        return ($this->random)(0, $ceiling);
    }
}

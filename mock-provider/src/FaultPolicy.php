<?php

declare(strict_types=1);

namespace MockProvider;

use InvalidArgumentException;

/**
 * Decides what happens to one request: how long it takes and whether it fails.
 * One random draw is split by the rates, so they add up and never overlap.
 */
final class FaultPolicy
{
    // The main app gives up after 3 s (task 8.1); the response is never read.
    public const TIMEOUT_MILLISECONDS = 5000;

    public function __construct(
        private readonly RandomSource $random,
        private readonly int $latencyMinMilliseconds,
        private readonly int $latencyMaxMilliseconds,
        private readonly float $serverErrorRate,
        private readonly float $rateLimitedRate,
        private readonly float $timeoutRate,
        private readonly float $acceptThenServerErrorRate,
    ) {
        // Fail at start-up: a bad rate would otherwise starve later faults and look like a healthy run.
        $rates = [$serverErrorRate, $rateLimitedRate, $timeoutRate, $acceptThenServerErrorRate];
        if (min($rates) < 0.0 || array_sum($rates) > 1.0) {
            throw new InvalidArgumentException('Fault rates must each be at least 0 and add up to at most 1, got '.implode(', ', $rates).'.');
        }
        if ($latencyMinMilliseconds < 0 || $latencyMinMilliseconds > $latencyMaxMilliseconds) {
            throw new InvalidArgumentException("Latency must satisfy 0 <= min <= max, got min {$latencyMinMilliseconds} ms and max {$latencyMaxMilliseconds} ms.");
        }
    }

    public static function fromEnvironment(RandomSource $random): self
    {
        // Defaults match .env.example.
        return new self(
            $random,
            (int) self::setting('MOCK_LATENCY_MIN_MS', '10'),
            (int) self::setting('MOCK_LATENCY_MAX_MS', '80'),
            (float) self::setting('MOCK_RATE_500', '0.05'),
            (float) self::setting('MOCK_RATE_429', '0.02'),
            (float) self::setting('MOCK_RATE_TIMEOUT', '0.01'),
            (float) self::setting('MOCK_RATE_ACCEPT_THEN_500', '0.02'),
        );
    }

    // getenv, not $_ENV: variables_order may omit E under php -S. Not `?:`, because "0" must switch a fault off.
    private static function setting(string $name, string $default): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Environment variable {$name} must be a number, got '{$value}'.");
        }

        return $value;
    }

    /**
     * A forced fault (the X-Mock-Fault header) skips latency and chance, so tests stay deterministic.
     */
    public function decide(?Fault $forced = null): FaultDecision
    {
        if ($forced !== null) {
            return new FaultDecision($forced, $forced === Fault::Timeout ? self::TIMEOUT_MILLISECONDS : 0);
        }

        $fault = $this->drawFault();
        $latency = $fault === Fault::Timeout
            ? self::TIMEOUT_MILLISECONDS
            : $this->random->between($this->latencyMinMilliseconds, $this->latencyMaxMilliseconds);

        return new FaultDecision($fault, $latency);
    }

    private function drawFault(): Fault
    {
        $draw = $this->random->unitInterval();
        $threshold = 0.0;

        foreach ($this->rates() as $rate) {
            $threshold += $rate['rate'];
            if ($draw < $threshold) {
                return $rate['fault'];
            }
        }

        return Fault::None;
    }

    /**
     * @return list<array{fault: Fault, rate: float}>
     */
    private function rates(): array
    {
        return [
            ['fault' => Fault::ServerError, 'rate' => $this->serverErrorRate],
            ['fault' => Fault::RateLimited, 'rate' => $this->rateLimitedRate],
            ['fault' => Fault::Timeout, 'rate' => $this->timeoutRate],
            ['fault' => Fault::AcceptThenServerError, 'rate' => $this->acceptThenServerErrorRate],
        ];
    }
}

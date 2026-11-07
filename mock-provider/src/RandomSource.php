<?php

declare(strict_types=1);

namespace MockProvider;

/**
 * Injected into FaultPolicy so a test can feed a fixed sequence and expect an exact distribution.
 */
interface RandomSource
{
    /** A number in [0, 1). */
    public function unitInterval(): float;

    public function between(int $min, int $max): int;
}

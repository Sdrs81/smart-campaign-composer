<?php

declare(strict_types=1);

namespace MockProvider;

final class SystemRandom implements RandomSource
{
    public function unitInterval(): float
    {
        return mt_rand() / (mt_getrandmax() + 1);
    }

    public function between(int $min, int $max): int
    {
        return random_int($min, $max);
    }
}

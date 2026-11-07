<?php

declare(strict_types=1);

namespace MockProvider;

final readonly class FaultDecision
{
    public function __construct(
        public Fault $fault,
        public int $latencyMilliseconds,
    ) {}
}

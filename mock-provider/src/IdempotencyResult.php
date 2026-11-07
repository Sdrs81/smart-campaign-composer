<?php

declare(strict_types=1);

namespace MockProvider;

final readonly class IdempotencyResult
{
    /**
     * @param  array<string, string>  $response
     */
    public function __construct(
        public IdempotencyOutcome $outcome,
        public array $response,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Segment;

/** Conditions joined by AND (K6). Built only by SegmentRuleParser, so an invalid rule cannot exist. */
final readonly class SegmentRule
{
    public const VERSION = 1;

    public const MIN_CONDITIONS = 1;

    public const MAX_CONDITIONS = 10;

    /** @param  non-empty-list<Condition>  $conditions */
    public function __construct(public int $version, public array $conditions) {}
}

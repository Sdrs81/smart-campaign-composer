<?php

declare(strict_types=1);

namespace App\Llm;

use App\Segment\SegmentRule;

/** A brief read into a validated rule; the only success value SegmentExtractor returns. */
final readonly class SegmentExtraction
{
    /**
     * @param  list<string>  $unsupported  parts of the brief that no field can express
     * @param  int  $attempts  1 when the first answer was valid, 2 and up after repair rounds
     */
    public function __construct(
        public SegmentRule $rule,
        public array $unsupported,
        public string $explanation,
        public int $attempts,
    ) {}
}

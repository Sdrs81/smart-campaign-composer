<?php

declare(strict_types=1);

namespace App\Segment;

/**
 * One checked comparison. Built only by SegmentRuleParser, so the operator is allowed for the field
 * and the value fits it.
 */
final readonly class Condition
{
    /** @param  string|int|float|list<string|int|float>|null  $value  null for is_null / not_null */
    public function __construct(
        public FieldDefinition $field,
        public Operator $operator,
        public string|int|float|array|null $value,
    ) {}
}

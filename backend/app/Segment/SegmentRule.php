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

    /**
     * The stored form; SegmentRuleParser reads the same shape back.
     *
     * @return array{version: int, conditions: list<array{field: string, operator: string, value: string|int|float|list<string|int|float>|null}>}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'conditions' => array_map(fn (Condition $condition): array => [
                'field' => $condition->field->name,
                'operator' => $condition->operator->value,
                'value' => $condition->value,
            ], $this->conditions),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Llm\Prompts;

use App\Segment\FieldDefinition;
use App\Segment\FieldRegistry;
use App\Segment\FieldType;
use App\Segment\Operator;
use App\Segment\SegmentRule;
use Carbon\CarbonInterface;

/**
 * Builds the segment prompt and its JSON schema from FieldRegistry, so fields, operators and values
 * cannot drift from what the parser accepts (K7).
 */
final class SegmentPrompt
{
    public const VERSION = 'segment-v1';

    // Built from __DIR__ rather than resource_path() so unit tests need no framework boot.
    private const TEMPLATE_PATH = __DIR__.'/../../../resources/prompts/segment.v1.md';

    private const DATE_FORMAT = 'Y-m-d';

    public function __construct(private readonly FieldRegistry $registry) {}

    /**
     * The fixed part: it holds nothing that changes per request (no date, no brief), so the bytes
     * stay identical between calls and prompt caching can reuse them.
     */
    public function system(): string
    {
        return strtr((string) file_get_contents(self::TEMPLATE_PATH), [
            '{{FIELD_TABLE}}' => $this->fieldTable(),
            '{{MAX_IN_VALUES}}' => (string) FieldDefinition::MAX_IN_VALUES,
            '{{DAYS_RANGE}}' => FieldDefinition::MIN_RELATIVE_DAYS.' to '.FieldDefinition::MAX_RELATIVE_DAYS,
            '{{MAX_CONDITIONS}}' => (string) SegmentRule::MAX_CONDITIONS,
        ]);
    }

    /** The varying part: today's date and the brief, which is wrapped so it reads as data. */
    public function userMessage(string $brief, CarbonInterface $today): string
    {
        return 'Today is '.$today->format(self::DATE_FORMAT).".\n\n<brief>".$brief.'</brief>';
    }

    /**
     * Describes structure only. Which operator fits which field, and the value limits, are enforced by
     * SegmentRuleParser, whose errors drive the repair round. Structured outputs also reject numeric
     * and array-size constraints, so none are declared here. An empty conditions list is allowed on
     * purpose: it is how the model says "nothing could be expressed".
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $condition = [
            'type' => 'object',
            'properties' => [
                'field' => ['type' => 'string', 'enum' => array_keys($this->registry->all())],
                'operator' => ['type' => 'string', 'enum' => $this->operatorNames()],
                'value' => ['anyOf' => [
                    ['type' => 'string'],
                    ['type' => 'number'],
                    ['type' => 'null'],
                    ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'number']]]],
                ]],
            ],
            'required' => ['field', 'operator', 'value'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'rule' => [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => 'integer', 'enum' => [SegmentRule::VERSION]],
                        'conditions' => ['type' => 'array', 'items' => $condition],
                    ],
                    'required' => ['version', 'conditions'],
                    'additionalProperties' => false,
                ],
                'unsupported_criteria' => ['type' => 'array', 'items' => ['type' => 'string']],
                'explanation' => ['type' => 'string'],
            ],
            'required' => ['rule', 'unsupported_criteria', 'explanation'],
            'additionalProperties' => false,
        ];
    }

    private function fieldTable(): string
    {
        $rows = ['| field | meaning | type | operators | allowed values |', '| --- | --- | --- | --- | --- |'];

        foreach ($this->registry->all() as $field) {
            $operators = implode(', ', array_map(fn (Operator $operator): string => $operator->value, $field->allowedOperators()));
            $rows[] = "| {$field->name} | {$field->label} | {$field->type->value} | {$operators} | {$this->valueHint($field)} |";
        }

        return implode("\n", $rows);
    }

    private function valueHint(FieldDefinition $field): string
    {
        return match ($field->type) {
            FieldType::Enum => implode(', ', $field->allowedValues()),
            FieldType::Number => "{$field->min} to {$field->max}",
            FieldType::Date => 'dates YYYY-MM-DD for between, whole days for the relative operators',
        };
    }

    /** @return list<string> every operator some registered field accepts, in a stable order */
    private function operatorNames(): array
    {
        $used = [];
        foreach ($this->registry->all() as $field) {
            foreach ($field->allowedOperators() as $operator) {
                $used[$operator->value] = true;
            }
        }

        return array_values(array_filter(
            array_map(fn (Operator $operator): string => $operator->value, Operator::cases()),
            fn (string $name): bool => isset($used[$name]),
        ));
    }
}

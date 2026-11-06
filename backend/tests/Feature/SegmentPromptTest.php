<?php

declare(strict_types=1);

use App\Llm\Prompts\SegmentPrompt;
use App\Segment\FieldRegistry;
use App\Segment\Operator;
use App\Segment\SegmentRule;
use App\Segment\SegmentRuleParser;
use Carbon\CarbonImmutable;

const SEGMENT_PROMPT_SNAPSHOT = __DIR__.'/../Fixtures/segment-prompt.v1.snapshot.txt';

function promptRegistry(): FieldRegistry
{
    return new FieldRegistry(fn (): array => ['Ankara', 'İstanbul', 'İzmir']);
}

function segmentPrompt(): SegmentPrompt
{
    return new SegmentPrompt(promptRegistry());
}

/** Keywords Anthropic structured outputs accept; anything else would be rejected or ignored. */
const SUPPORTED_SCHEMA_KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'anyOf'];

const SCHEMA_TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

/** @param  array<string, mixed>  $node */
function assertSchemaNode(array $node, string $path): void
{
    expect(array_diff(array_keys($node), SUPPORTED_SCHEMA_KEYWORDS))->toBe([], "{$path} uses an unsupported keyword");

    if (isset($node['type'])) {
        expect(SCHEMA_TYPES)->toContain($node['type']);
    }

    if (isset($node['enum'])) {
        expect($node['enum'])->not->toBeEmpty()->and($node['enum'])->toBe(array_values(array_unique($node['enum'])));
    }

    if (($node['type'] ?? null) === 'object') {
        expect($node['additionalProperties'])->toBeFalse("{$path} must set additionalProperties: false")
            ->and($node['required'])->toBe(array_keys($node['properties']), "{$path} must require every property");
        foreach ($node['properties'] as $name => $child) {
            assertSchemaNode($child, "{$path}.{$name}");
        }
    }

    if (($node['type'] ?? null) === 'array') {
        assertSchemaNode($node['items'], "{$path}[]");
    }

    foreach ($node['anyOf'] ?? [] as $index => $option) {
        assertSchemaNode($option, "{$path}|{$index}");
    }
}

it('matches the stored snapshot of the fixed system prompt', function (): void {
    expect(str_replace("\r\n", "\n", segmentPrompt()->system()))
        ->toBe(str_replace("\r\n", "\n", (string) file_get_contents(SEGMENT_PROMPT_SNAPSHOT)));
});

it('keeps the system prompt free of anything that changes per request', function (): void {
    expect(segmentPrompt()->system())
        ->not->toMatch('/\d{4}-\d{2}-\d{2}/')
        ->not->toContain('{{');
});

it('lists every registry field, operator and city in the system prompt', function (): void {
    $system = segmentPrompt()->system();

    foreach (array_keys(promptRegistry()->all()) as $field) {
        expect($system)->toContain("| {$field} |");
    }
    foreach (Operator::cases() as $operator) {
        expect($system)->toContain($operator->value);
    }
    expect($system)->toContain('Ankara, İstanbul, İzmir');
});

it('puts today and the wrapped brief in the user message', function (): void {
    $message = segmentPrompt()->userMessage('Sepetini unutanlar', CarbonImmutable::parse('2026-11-06'));

    expect($message)->toBe("Today is 2026-11-06.\n\n<brief>Sepetini unutanlar</brief>");
});

it('produces a schema that only uses structured-output features and lists the registry', function (): void {
    $schema = segmentPrompt()->jsonSchema();

    assertSchemaNode($schema, '$');
    $condition = $schema['properties']['rule']['properties']['conditions']['items']['properties'];
    expect($condition['field']['enum'])->toBe(array_keys(promptRegistry()->all()))
        ->and($condition['operator']['enum'])->toBe(array_map(fn (Operator $operator): string => $operator->value, Operator::cases()))
        ->and($schema['properties']['rule']['properties']['version']['enum'])->toBe([SegmentRule::VERSION])
        ->and(json_encode($schema))->toBeString();
});

it('ships examples that the real parser accepts and that follow the schema', function (): void {
    preg_match_all('#<output>(.+?)</output>#s', (string) file_get_contents(resource_path('prompts/segment.v1.md')), $matches);
    $parser = new SegmentRuleParser(promptRegistry());

    expect($matches[1])->toHaveCount(3);
    foreach ($matches[1] as $json) {
        $output = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        expect(array_keys($output))->toBe(['rule', 'unsupported_criteria', 'explanation']);

        expect($parser->parse($output['rule']))->toBeInstanceOf(SegmentRule::class);
    }
});

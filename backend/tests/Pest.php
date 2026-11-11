<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    // Any HTTP call a test did not fake fails, so nothing can reach a real API.
    ->beforeEach(fn () => Http::preventStrayRequests())
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** Keywords Anthropic structured outputs accept; anything else would be rejected or ignored. */
const SUPPORTED_SCHEMA_KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'anyOf'];

const SCHEMA_TYPES = ['object', 'array', 'string', 'number', 'integer', 'boolean', 'null'];

/** Shared by the prompt tests: fails when a JSON schema uses a feature structured outputs reject. @param  array<string, mixed>  $node */
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

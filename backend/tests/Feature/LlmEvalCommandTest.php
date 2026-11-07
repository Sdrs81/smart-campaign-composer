<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Segment\FieldRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The segment prompt and parser take the city list from the customers.
beforeEach(fn () => Customer::factory()->create(['city' => 'Ankara']));

/**
 * Writes a copy of the golden file with one change applied and returns its path.
 *
 * @param  callable(array<string, mixed>): array<string, mixed>  $change
 */
function changedGoldenFile(callable $change): string
{
    $golden = json_decode((string) file_get_contents(base_path('resources/llm-eval/briefs.json')), true, flags: JSON_THROW_ON_ERROR);
    $path = tempnam(sys_get_temp_dir(), 'llm-eval-');
    file_put_contents($path, json_encode($change($golden), JSON_THROW_ON_ERROR));

    return $path;
}

/** @param  array<string, mixed>  $golden */
function withBrief(array $golden, string $id, callable $change): array
{
    $golden['briefs'] = array_map(fn (array $brief): array => $brief['id'] === $id ? $change($brief) : $brief, $golden['briefs']);

    return $golden;
}

it('has between 8 and 10 golden briefs covering every field type and an unsupported criterion', function (): void {
    $golden = json_decode((string) file_get_contents(base_path('resources/llm-eval/briefs.json')), true, flags: JSON_THROW_ON_ERROR);
    $registry = app(FieldRegistry::class);
    $claims = [];
    array_walk_recursive($golden['briefs'], function (mixed $value, string|int $key) use (&$claims): void {
        if ($key === 'field') {
            $claims[] = $value;
        }
    });
    $types = array_unique(array_map(fn (string $field): string => $registry->find($field)->type->value, array_unique($claims)));
    sort($types);

    expect(count($golden['briefs']))->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(10)
        ->and($types)->toBe(['date', 'enum', 'number'])
        ->and(array_column($golden['briefs'], 'id'))->toHaveCount(count(array_unique(array_column($golden['briefs'], 'id'))))
        ->and(json_encode($golden['briefs']))->toContain('"unsupported":true')->toContain('unsupported_only');
});

it('passes every golden brief at 100 percent with the fake driver', function (): void {
    $this->artisan('llm:eval')
        ->expectsOutputToContain('Driver: fake')
        ->expectsOutputToContain('Scored 10 of 10 briefs')
        ->expectsOutputToContain('10/10 (100%)')
        ->expectsOutputToContain('Average segment attempts: 1.00')
        ->assertSuccessful();
});

it('runs a single brief with --only', function (): void {
    $this->artisan('llm:eval --only=example')
        ->expectsOutputToContain('Scored 1 of 1 briefs')
        ->doesntExpectOutputToContain('web-books')
        ->assertSuccessful();
});

it('names the available ids when --only matches nothing', function (): void {
    $this->artisan('llm:eval --only=nope')
        ->expectsOutputToContain("No brief with id 'nope'; available: example, ")
        ->assertFailed();
});

it('fails and says which expected condition is missing when the answer has a wrong value', function (): void {
    $file = changedGoldenFile(fn (array $golden): array => withBrief($golden, 'example', function (array $brief): array {
        $brief['fake_segment']['rule']['conditions'][0]['value'] = 31;

        return $brief;
    }));

    $this->artisan("llm:eval --file={$file}")
        ->expectsOutputToContain('example: missing condition last_cart_add_at within_last_days 30.')
        ->expectsOutputToContain('9/10 (90%)')
        ->assertFailed();
});

it('fails when an unsupported criterion was expected but not reported', function (): void {
    $file = changedGoldenFile(fn (array $golden): array => withBrief($golden, 'unsupported-mixed', function (array $brief): array {
        $brief['fake_segment']['unsupported_criteria'] = [];

        return $brief;
    }));

    $this->artisan("llm:eval --file={$file}")
        ->expectsOutputToContain('unsupported-mixed: expected an unsupported criterion, none was reported.')
        ->assertFailed();
});

it('fails when a condition is invented', function (): void {
    $file = changedGoldenFile(fn (array $golden): array => withBrief($golden, 'web-books', function (array $brief): array {
        $brief['fake_segment']['rule']['conditions'][] = ['field' => 'gender', 'operator' => 'eq', 'value' => 'female'];

        return $brief;
    }));

    $this->artisan("llm:eval --file={$file}")
        ->expectsOutputToContain('web-books: expected 2 conditions, got 3.')
        ->assertFailed();
});

it('fails a brief that should be unsupported-only when a rule comes back', function (): void {
    $file = changedGoldenFile(fn (array $golden): array => withBrief($golden, 'unsupported-only', function (array $brief): array {
        $brief['fake_segment']['rule']['conditions'] = [['field' => 'gender', 'operator' => 'eq', 'value' => 'female']];

        return $brief;
    }));

    $this->artisan("llm:eval --file={$file}")
        ->expectsOutputToContain('unsupported-only: expected no condition to be expressible')
        ->assertFailed();
});

it('reports a call that broke as an error left out of the rates, not as a model failure', function (): void {
    config(['llm.fake.dev_scenarios' => true]);
    $file = changedGoldenFile(fn (array $golden): array => withBrief($golden, 'web-books', function (array $brief): array {
        $brief['fake_segment'] = 'not a rule';

        return $brief;
    }));

    $this->artisan("llm:eval --file={$file}")
        ->expectsOutputToContain('Scored 9 of 10 briefs (1 errors left out')
        ->expectsOutputToContain('9/9 (100%)')
        ->assertFailed();
});

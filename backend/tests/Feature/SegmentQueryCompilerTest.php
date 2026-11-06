<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Models\Customer;
use App\Segment\FieldRegistry;
use App\Segment\SegmentQueryCompiler;
use App\Segment\SegmentRule;
use App\Segment\SegmentRuleParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const FIXED_NOW = '2026-06-15 12:00:00';

beforeEach(function (): void {
    $this->travelTo(FIXED_NOW);
});

/** @param  list<array<string, mixed>>  $conditions */
function compiledQuery(array $conditions): Builder
{
    $parser = new SegmentRuleParser(new FieldRegistry(fn (): array => ['Ankara', 'İstanbul']));
    $rule = $parser->parse(['version' => 1, 'conditions' => $conditions]);
    expect($rule)->toBeInstanceOf(SegmentRule::class);

    return (new SegmentQueryCompiler)->compile($rule);
}

/** @return list<string> last names (used as labels) of the matching customers, sorted */
function matchedLabels(Builder $query): array
{
    return $query->pluck('last_name')->sort()->values()->all();
}

function customer(string $label, array $attributes = []): Customer
{
    return Customer::factory()->create(['last_name' => $label, ...$attributes]);
}

it('matches the sample brief: cart activity in 30 days and no purchase in 30 days, never-purchased included', function (): void {
    customer('A', ['city' => 'İstanbul', 'last_cart_add_at' => now()->subDays(10), 'last_purchase_at' => now()->subDays(60)]);
    customer('B', ['city' => 'İstanbul', 'last_cart_add_at' => now()->subDays(5), 'last_purchase_at' => null]);
    customer('C', ['city' => 'İstanbul', 'last_cart_add_at' => now()->subDays(5), 'last_purchase_at' => now()->subDays(10)]);
    customer('D', ['city' => 'Ankara', 'last_cart_add_at' => now()->subDays(5), 'last_purchase_at' => null]);
    customer('E', ['city' => 'İstanbul', 'last_cart_add_at' => now()->subDays(45), 'last_purchase_at' => null]);
    customer('F', ['city' => 'İstanbul', 'last_cart_add_at' => null, 'last_purchase_at' => null]);

    $query = compiledQuery([
        ['field' => 'city', 'operator' => 'eq', 'value' => 'İstanbul'],
        ['field' => 'last_cart_add_at', 'operator' => 'within_last_days', 'value' => 30],
        ['field' => 'last_purchase_at', 'operator' => 'not_within_last_days', 'value' => 30],
    ]);

    expect(matchedLabels($query))->toBe(['A', 'B']);
});

it('matches an enum list and an age range, boundaries included', function (): void {
    $today = now()->startOfDay();

    customer('gold-30', ['loyalty_tier' => 'gold', 'birth_date' => $today->copy()->subYears(30)]);
    customer('plat-25-today', ['loyalty_tier' => 'platinum', 'birth_date' => $today->copy()->subYears(25)]);
    customer('plat-40-birthday-tomorrow', ['loyalty_tier' => 'platinum', 'birth_date' => $today->copy()->subYears(41)->addDay()]);
    customer('gold-41-today', ['loyalty_tier' => 'gold', 'birth_date' => $today->copy()->subYears(41)]);
    customer('gold-24-tomorrow', ['loyalty_tier' => 'gold', 'birth_date' => $today->copy()->subYears(25)->addDay()]);
    customer('silver-30', ['loyalty_tier' => 'silver', 'birth_date' => $today->copy()->subYears(30)]);

    $query = compiledQuery([
        ['field' => 'loyalty_tier', 'operator' => 'in', 'value' => ['gold', 'platinum']],
        ['field' => 'age', 'operator' => 'between', 'value' => [25, 40]],
    ]);

    expect(matchedLabels($query))->toBe(['gold-30', 'plat-25-today', 'plat-40-birthday-tomorrow']);
});

it('compiles every age operator against birth_date', function (array $condition, array $expected): void {
    $today = now()->startOfDay();
    foreach ([20, 30, 40] as $age) {
        customer("age-{$age}", ['birth_date' => $today->copy()->subYears($age)->subMonth()]);
    }

    expect(matchedLabels(compiledQuery([['field' => 'age', ...$condition]])))->toBe($expected);
})->with([
    'eq' => [['operator' => 'eq', 'value' => 30], ['age-30']],
    'neq' => [['operator' => 'neq', 'value' => 30], ['age-20', 'age-40']],
    'gt' => [['operator' => 'gt', 'value' => 30], ['age-40']],
    'gte' => [['operator' => 'gte', 'value' => 30], ['age-30', 'age-40']],
    'lt' => [['operator' => 'lt', 'value' => 30], ['age-20']],
    'lte' => [['operator' => 'lte', 'value' => 30], ['age-20', 'age-30']],
    'not_null' => [['operator' => 'not_null'], ['age-20', 'age-30', 'age-40']],
    'is_null' => [['operator' => 'is_null'], []],
    'fractional eq matches nobody' => [['operator' => 'eq', 'value' => 30.5], []],
]);

it('compiles number, enum and date operators', function (array $condition, array $expected): void {
    customer('low', ['orders_count' => 1, 'total_spent' => 100, 'favorite_category' => 'moda', 'registered_at' => '2025-01-10', 'last_login_at' => null]);
    customer('mid', ['orders_count' => 5, 'total_spent' => 900, 'favorite_category' => 'spor', 'registered_at' => '2025-06-30 23:00:00', 'last_login_at' => now()]);
    customer('high', ['orders_count' => 12, 'total_spent' => 5000, 'favorite_category' => null, 'registered_at' => '2025-07-01', 'last_login_at' => now()]);

    expect(matchedLabels(compiledQuery([$condition])))->toBe($expected);
})->with([
    'number gte' => [['field' => 'orders_count', 'operator' => 'gte', 'value' => 5], ['high', 'mid']],
    'number between' => [['field' => 'total_spent', 'operator' => 'between', 'value' => [100, 900]], ['low', 'mid']],
    'number lt' => [['field' => 'orders_count', 'operator' => 'lt', 'value' => 5], ['low']],
    'number neq' => [['field' => 'orders_count', 'operator' => 'neq', 'value' => 5], ['high', 'low']],
    'enum eq' => [['field' => 'favorite_category', 'operator' => 'eq', 'value' => 'moda'], ['low']],
    'enum neq keeps null' => [['field' => 'favorite_category', 'operator' => 'neq', 'value' => 'moda'], ['high', 'mid']],
    'enum in' => [['field' => 'favorite_category', 'operator' => 'in', 'value' => ['moda', 'spor']], ['low', 'mid']],
    'enum not_in keeps null' => [['field' => 'favorite_category', 'operator' => 'not_in', 'value' => ['moda', 'spor']], ['high']],
    'enum is_null' => [['field' => 'favorite_category', 'operator' => 'is_null'], ['high']],
    'date not_null' => [['field' => 'last_login_at', 'operator' => 'not_null'], ['high', 'mid']],
    'date between covers the whole last day' => [['field' => 'registered_at', 'operator' => 'between', 'value' => ['2025-01-01', '2025-06-30']], ['low', 'mid']],
]);

it('measures relative days from the frozen now', function (): void {
    customer('inside', ['last_login_at' => now()->subDays(30)->addMinute()]);
    customer('outside', ['last_login_at' => now()->subDays(30)->subMinute()]);

    $within = compiledQuery([['field' => 'last_login_at', 'operator' => 'within_last_days', 'value' => 30]]);
    expect(matchedLabels($within))->toBe(['inside']);

    $this->travelTo(now()->addDays(31));
    $later = compiledQuery([['field' => 'last_login_at', 'operator' => 'within_last_days', 'value' => 30]]);
    expect(matchedLabels($later))->toBe([]);
});

it('keeps rule values out of the SQL text and binds them instead', function (): void {
    $sql = compiledQuery([
        ['field' => 'city', 'operator' => 'eq', 'value' => 'İstanbul'],
        ['field' => 'loyalty_tier', 'operator' => 'in', 'value' => ['gold', 'platinum']],
        ['field' => 'last_cart_add_at', 'operator' => 'within_last_days', 'value' => 30],
        ['field' => 'last_purchase_at', 'operator' => 'not_within_last_days', 'value' => 30],
        ['field' => 'age', 'operator' => 'between', 'value' => [25, 40]],
        ['field' => 'total_spent', 'operator' => 'gte', 'value' => 1234],
    ])->toSql();

    expect($sql)->toContain('?')
        ->not->toContain('İstanbul')
        ->not->toContain('gold')
        ->not->toContain('platinum')
        ->not->toContain('1234')
        ->not->toContain('2026')
        ->not->toContain('2025');
});

it('filters by channel consent and a usable address, separately from the rule', function (): void {
    customer('both');
    customer('no-sms-consent', ['sms_opt_in' => false]);
    customer('no-phone', ['phone' => null]);
    customer('empty-phone', ['phone' => '']);
    customer('no-email-consent', ['email_opt_in' => false]);
    customer('no-email', ['email' => null]);

    $compiler = new SegmentQueryCompiler;
    $everyone = compiledQuery([['field' => 'language', 'operator' => 'eq', 'value' => 'tr']]);

    expect(matchedLabels($everyone))->toHaveCount(6)
        ->and(matchedLabels($compiler->withChannelConsent(clone $everyone, Channel::Sms)))->toBe(['both', 'no-email', 'no-email-consent'])
        ->and(matchedLabels($compiler->withChannelConsent(clone $everyone, Channel::Email)))->toBe(['both', 'empty-phone', 'no-phone', 'no-sms-consent']);
});

it('matches nobody for an age below zero instead of customers born in the future', function (): void {
    customer('future-born', ['birth_date' => now()->addYear()]);
    customer('adult', ['birth_date' => now()->subYears(30)->subMonth()]);

    $lessThanZero = compiledQuery([['field' => 'age', 'operator' => 'lt', 'value' => 0]]);

    expect(matchedLabels($lessThanZero))->toBe([]);
});

it('agrees with Customer::canReceive about who may receive each channel', function (): void {
    $variants = [
        [],
        ['sms_opt_in' => false],
        ['email_opt_in' => false],
        ['phone' => null],
        ['phone' => ''],
        ['email' => null],
        ['sms_opt_in' => false, 'email_opt_in' => false],
    ];
    foreach ($variants as $index => $attributes) {
        customer("variant-{$index}", $attributes);
    }

    $compiler = new SegmentQueryCompiler;
    foreach (Channel::cases() as $channel) {
        $bySql = matchedLabels($compiler->withChannelConsent(Customer::query(), $channel));
        $byModel = Customer::all()->filter(fn (Customer $customer): bool => $customer->canReceive($channel))->pluck('last_name')->sort()->values()->all();

        expect($bySql)->toBe($byModel);
    }
});

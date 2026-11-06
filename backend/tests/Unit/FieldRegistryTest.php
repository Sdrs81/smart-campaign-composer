<?php

declare(strict_types=1);

use App\Segment\FieldRegistry;
use App\Segment\FieldType;
use App\Segment\Operator;

function fieldRegistry(): FieldRegistry
{
    return new FieldRegistry(fn (): array => ['Ankara', 'İstanbul']);
}

it('allows exactly the listed operators per type and rejects every other', function (string $type, array $allowed): void {
    $fieldType = FieldType::from($type);
    $field = collect(fieldRegistry()->all())->first(fn ($definition): bool => $definition->type === $fieldType);

    $allowedNames = array_map(fn (Operator $operator): string => $operator->value, $field->allowedOperators());
    expect($allowedNames)->toBe($allowed);

    foreach (Operator::cases() as $operator) {
        expect($field->allows($operator))->toBe(in_array($operator->value, $allowed, true));
    }
})->with([
    'enum' => ['enum', ['eq', 'neq', 'in', 'not_in', 'is_null', 'not_null']],
    'number' => ['number', ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_null', 'not_null']],
    'date' => ['date', ['within_last_days', 'not_within_last_days', 'between', 'is_null', 'not_null']],
]);

it('registers every segmentable field with the planned type', function (): void {
    $expected = [
        'city' => 'enum', 'gender' => 'enum', 'language' => 'enum', 'platform' => 'enum',
        'loyalty_tier' => 'enum', 'favorite_category' => 'enum',
        'age' => 'number', 'orders_count' => 'number', 'total_spent' => 'number',
        'avg_order_value' => 'number', 'cart_items_count' => 'number', 'cart_value' => 'number',
        'registered_at' => 'date', 'last_login_at' => 'date', 'first_purchase_at' => 'date',
        'last_purchase_at' => 'date', 'last_cart_add_at' => 'date',
    ];

    $actual = collect(fieldRegistry()->all())->map(fn ($definition): string => $definition->type->value)->all();

    expect($actual)->toBe($expected);
});

it('does not expose contact details, names or consent flags', function (string $name): void {
    expect(fieldRegistry()->find($name))->toBeNull();
})->with(['email', 'phone', 'first_name', 'last_name', 'sms_opt_in', 'email_opt_in', 'unknown_field']);

it('maps age to birth_date and every other field to its own column', function (): void {
    $registry = fieldRegistry();

    expect($registry->find('age')->column)->toBe('birth_date')
        ->and($registry->find('age')->derivedFromBirthDate)->toBeTrue()
        ->and($registry->find('last_purchase_at')->column)->toBe('last_purchase_at')
        ->and($registry->find('last_purchase_at')->derivedFromBirthDate)->toBeFalse();
});

it('reads city values from the injected source and keeps the other lists static', function (): void {
    $registry = fieldRegistry();

    expect($registry->find('city')->allowedValues())->toBe(['Ankara', 'İstanbul'])
        ->and($registry->find('loyalty_tier')->allowedValues())->toBe(['bronze', 'silver', 'gold', 'platinum'])
        ->and($registry->find('age')->allowedValues())->toBe([])
        ->and($registry->find('age')->max)->toBe(120);
});

it('does not read the city source until its values are asked for', function (): void {
    $calls = 0;
    $registry = new FieldRegistry(function () use (&$calls): array {
        $calls++;

        return ['Ankara'];
    });

    $registry->find('city');
    expect($calls)->toBe(0);

    $registry->find('city')->allowedValues();
    expect($calls)->toBe(1);
});

it('gives every field a label', function (): void {
    foreach (fieldRegistry()->all() as $definition) {
        expect($definition->label)->not->toBe('');
    }
});

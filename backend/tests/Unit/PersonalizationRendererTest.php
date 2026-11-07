<?php

declare(strict_types=1);

use App\Content\PersonalizationRenderer;
use App\Content\Variables;

const FAMILY_EMOJI = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";

it('lists the registered variables and their fallbacks', function (): void {
    expect(Variables::names())->toBe(['first_name', 'last_name', 'city'])
        ->and(Variables::has('city'))->toBeTrue()
        ->and(Variables::has('email'))->toBeFalse()
        ->and(Variables::fallback('first_name'))->toBe('Değerli müşterimiz');
    expect(fn () => Variables::fallback('email'))->toThrow(InvalidArgumentException::class, "Unknown variable 'email'");
});

it('fills variables with the customer values', function (): void {
    $text = (new PersonalizationRenderer)->render(
        'Merhaba {{first_name}} {{last_name}}, {{city}} şubemiz açıldı!',
        ['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'city' => 'İzmir'],
    );

    expect($text)->toBe('Merhaba Ayşe Yılmaz, İzmir şubemiz açıldı!');
});

it('uses the fallback for a missing, empty or blank value', function (?string $value): void {
    $text = (new PersonalizationRenderer)->render('Merhaba {{first_name}}', ['first_name' => $value]);

    expect($text)->toBe('Merhaba Değerli müşterimiz');
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
]);

it('uses the fallback when the value is not supplied at all', function (): void {
    expect((new PersonalizationRenderer)->render('{{city}}', []))->toBe('bölgeniz');
});

it('accepts spaces inside the braces and repeats a variable', function (): void {
    $text = (new PersonalizationRenderer)->render('{{ first_name }} / {{first_name}}', ['first_name' => 'Can']);

    expect($text)->toBe('Can / Can');
});

it('inserts values as plain text, so a value that looks like a placeholder is not expanded', function (): void {
    $text = (new PersonalizationRenderer)->render('{{first_name}} / {{city}}', ['first_name' => '{{city}}', 'city' => 'Ankara']);

    expect($text)->toBe('{{city}} / Ankara');
});

it('finds unknown variables once each, in order, and ignores known ones', function (): void {
    $renderer = new PersonalizationRenderer;

    expect($renderer->unknownVariables('{{unknown}} {{first_name}} {{unknown}} {{Other}} {{First_Name}}'))
        ->toBe(['unknown', 'Other', 'First_Name'])
        ->and($renderer->unknownVariables('Merhaba {{first_name}}, {{ city }}'))->toBe([]);
});

it('leaves an unknown placeholder untouched when rendering', function (): void {
    expect((new PersonalizationRenderer)->render('Selam {{unknown}} {{First_Name}}', ['first_name' => 'Can']))
        ->toBe('Selam {{unknown}} {{First_Name}}');
});

it('does not treat malformed braces as variables', function (): void {
    expect((new PersonalizationRenderer)->unknownVariables('{{}} {{ }} {first_name} {{1abc}} {{first name}}'))->toBe([]);
});

it('counts visible characters, not bytes or code points', function (string $text, int $expected): void {
    expect((new PersonalizationRenderer)->visibleLength($text))->toBe($expected);
})->with([
    'ascii' => ['Merhaba', 7],
    'turkish letters' => ['şğüöçıİ', 7],
    'thumbs up emoji' => ["\u{1F44D}", 1],
    'family emoji (3 people joined)' => [FAMILY_EMOJI, 1],
    'letter with combining accent' => ["e\u{0301}", 1],
    'mixed' => ["Merhaba \u{1F44D}".FAMILY_EMOJI.' ş!', 13],
    'empty' => ['', 0],
]);

it('falls back to the code point count for invalid UTF-8 instead of failing', function (): void {
    // The PHP manual: grapheme_strlen returns null or false for invalid UTF-8.
    expect((new PersonalizationRenderer)->visibleLength("ab\xFF"))->toBeInt();
});

it('detects leftover braces after the well-formed placeholders are removed', function (string $template, bool $malformed): void {
    expect((new PersonalizationRenderer)->hasMalformedBraces($template))->toBe($malformed);
})->with([
    'clean' => ['Merhaba {{first_name}} {{ city }}', false],
    'no braces' => ['Merhaba', false],
    'unclosed' => ['Merhaba {{first_name', true],
    'closing only' => ['first_name}} Merhaba', true],
    'empty' => ['{{}}', true],
    'blank' => ['{{ }}', true],
    'space in name' => ['{{first name}}', true],
    'digit first' => ['{{1abc}}', true],
    'unknown but well-formed' => ['{{unknown}}', false],
    'triple braces' => ['Merhaba {{{first_name}}}', true],
]);

it('shows why code points are the wrong unit: the family emoji is five of them', function (): void {
    expect(mb_strlen(FAMILY_EMOJI))->toBe(5)
        ->and((new PersonalizationRenderer)->visibleLength(FAMILY_EMOJI))->toBe(1);
});

it('computes the worst-case length with the longest value of each variable', function (): void {
    $renderer = new PersonalizationRenderer;

    expect($renderer->worstCaseLength('Merhaba {{first_name}}!', ['first_name' => 25]))->toBe(8 + 25 + 1)
        ->and($renderer->worstCaseLength('Merhaba {{ first_name }}!', ['first_name' => 25]))->toBe(8 + 25 + 1)
        ->and($renderer->worstCaseLength('Merhaba', []))->toBe(7);
});

it('counts the fallback when it is longer than any real value', function (): void {
    // "Değerli müşterimiz" is 18 characters; a customer with no first name gets it.
    expect((new PersonalizationRenderer)->worstCaseLength('Merhaba {{first_name}}!', ['first_name' => 5]))->toBe(8 + 18 + 1);
});

it('counts every occurrence of a variable', function (): void {
    expect((new PersonalizationRenderer)->worstCaseLength('{{city}} {{city}}', ['city' => 3]))->toBe(8 + 1 + 8);
});

it('counts an unknown placeholder as the literal text it will stay', function (): void {
    expect((new PersonalizationRenderer)->worstCaseLength('Selam {{ad}} {{first_name}}', ['first_name' => 20]))->toBe(6 + 6 + 1 + 20);
});

it('measures the worst case in visible characters around emoji and Turkish letters', function (): void {
    $template = "Merhaba \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} {{first_name}} şğ";

    expect((new PersonalizationRenderer)->worstCaseLength($template, ['first_name' => 20]))->toBe(8 + 1 + 1 + 20 + 1 + 2);
});

it('makes one pass: the worst case grows linearly with the template', function (): void {
    $renderer = new PersonalizationRenderer;
    $template = str_repeat('a{{city}}', 2000);

    expect($renderer->worstCaseLength($template, ['city' => 10]))->toBe(2000 * 11)
        ->and(strlen($renderer->render($template, ['city' => 'Ankara'])))->toBe(2000 * 7);
});

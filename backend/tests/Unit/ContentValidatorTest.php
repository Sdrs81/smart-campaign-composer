<?php

declare(strict_types=1);

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Content\PersonalizationRenderer;
use App\Enums\Channel;
use App\Segment\ValidationErrors;

const THUMBS_UP = "\u{1F44D}";

function contentValidator(): ContentValidator
{
    return new ContentValidator(new PersonalizationRenderer, 160, 80, 2000);
}

/** @param  array<string, int>  $longest */
function smsErrors(string $body, array $longest = [], ?string $subject = null): ?ValidationErrors
{
    return contentValidator()->validate(new MessageContent(Channel::Sms, $subject, $body), $longest);
}

/** @param  array<string, int>  $longest */
function emailErrors(?string $subject, string $body, array $longest = []): ?ValidationErrors
{
    return contentValidator()->validate(new MessageContent(Channel::Email, $subject, $body), $longest);
}

it('accepts a valid SMS and a valid email', function (): void {
    expect(smsErrors('Merhaba {{first_name}}, sepetinde ürün kaldı!', ['first_name' => 12]))->toBeNull()
        ->and(emailErrors('Sepetin seni bekliyor', 'Merhaba {{ first_name }}, {{city}} için indirim var.', ['first_name' => 12, 'city' => 9]))->toBeNull();
});

it('stops an SMS at 160 visible characters', function (string $body, bool $valid): void {
    $errors = smsErrors($body);

    expect($errors === null)->toBe($valid);
    if (! $valid) {
        expect($errors->errors[0]['path'])->toBe('sms.body')
            ->and($errors->errors[0]['message'])->toContain('161')->toContain('limit is 160');
    }
})->with([
    '160 ascii' => [str_repeat('a', 160), true],
    '161 ascii' => [str_repeat('a', 161), false],
    '160 turkish letters (2 bytes each)' => [str_repeat('ş', 160), true],
    '161 turkish letters' => [str_repeat('ğ', 161), false],
    '159 letters + 1 emoji = 160' => [str_repeat('a', 159).THUMBS_UP, true],
    '159 letters + 2 emoji = 161' => [str_repeat('a', 159).THUMBS_UP.THUMBS_UP, false],
    '160 family emoji (800 code points)' => [str_repeat("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 160), true],
    '161 family emoji' => [str_repeat("\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}", 161), false],
]);

it('stops an email subject at 80 visible characters', function (string $subject, bool $valid): void {
    $errors = emailErrors($subject, 'Gövde');

    expect($errors === null)->toBe($valid);
    if (! $valid) {
        expect($errors->errors[0]['path'])->toBe('email.subject')->and($errors->errors[0]['message'])->toContain('limit is 80');
    }
})->with([
    '80 ascii' => [str_repeat('a', 80), true],
    '81 ascii' => [str_repeat('a', 81), false],
    '80 turkish letters' => [str_repeat('ı', 80), true],
    '81 turkish letters' => [str_repeat('İ', 81), false],
    '79 letters + emoji' => [str_repeat('a', 79).THUMBS_UP, true],
    '80 letters + emoji' => [str_repeat('a', 80).THUMBS_UP, false],
]);

it('stops an email body at 2000 visible characters', function (int $length, bool $valid): void {
    $errors = emailErrors('Konu', str_repeat('ş', $length));

    expect($errors === null)->toBe($valid);
    if (! $valid) {
        expect($errors->errors[0]['path'])->toBe('email.body');
    }
})->with([
    '2000' => [2000, true],
    '2001' => [2001, false],
]);

it('counts the longest customer value, not the width of the placeholder', function (): void {
    // 132 + 8 = 140 characters of text around {{first_name}}; the raw template is only 154 long.
    $body = 'Merhaba '.str_repeat('a', 132).'{{first_name}}';

    expect(smsErrors($body, ['first_name' => 20]))->toBeNull()
        ->and(smsErrors($body, ['first_name' => 21])->errors[0]['message'])->toContain('161')
        ->and(smsErrors($body, ['first_name' => 30])->errors[0]['message'])->toContain('170');
});

it('counts the fallback when it is longer than any real value', function (): void {
    // "Değerli müşterimiz" is 18 characters: 140 + 18 = 158 with no known values, but 143 + 18 = 161 breaks it.
    expect(smsErrors('Merhaba '.str_repeat('a', 132).'{{first_name}}'))->toBeNull()
        ->and(smsErrors('Merhaba '.str_repeat('a', 135).'{{first_name}}')->errors[0]['message'])->toContain('161');
});

it('rejects empty and blank text with the path of the field', function (): void {
    expect(smsErrors('')->errors)->toBe([['path' => 'sms.body', 'message' => 'must not be empty.']])
        ->and(smsErrors("  \n ")->errors[0]['path'])->toBe('sms.body')
        ->and(emailErrors('', 'Gövde')->errors)->toBe([['path' => 'email.subject', 'message' => 'must not be empty.']])
        ->and(emailErrors(null, 'Gövde')->errors[0]['path'])->toBe('email.subject')
        ->and(emailErrors('Konu', ' ')->errors[0]['path'])->toBe('email.body');
});

it('rejects a subject on an SMS', function (): void {
    expect(smsErrors('Merhaba', [], 'Konu')->errors[0])->toBe(['path' => 'sms.subject', 'message' => 'an SMS has no subject; leave it out.']);
});

it('rejects unknown variables and names the allowed ones', function (): void {
    $message = smsErrors('Merhaba {{ad}} {{First_Name}} {{first_name}}')->errors[0]['message'];

    expect($message)->toContain('{{ad}}, {{First_Name}}')->toContain('allowed: {{first_name}}, {{last_name}}, {{city}}')
        ->not->toContain('unknown variable {{first_name}}');
    expect(emailErrors('Selam {{ad}}', 'Gövde')->errors[0]['path'])->toBe('email.subject');
});

it('rejects a malformed brace', function (string $body): void {
    $errors = smsErrors($body);

    expect($errors->errors[0]['path'])->toBe('sms.body')->and($errors->errors[0]['message'])->toContain('malformed');
})->with([
    'unclosed' => ['Merhaba {{first_name'],
    'no opening' => ['Merhaba first_name}}'],
    'empty' => ['Merhaba {{}}'],
    'space in name' => ['Merhaba {{first name}}'],
    'digit first' => ['Merhaba {{1abc}}'],
]);

it('reports every problem at once, field by field', function (): void {
    $errors = emailErrors('', str_repeat('a', 2001).'{{ad}} {{x')->errors;

    expect(array_column($errors, 'path'))->toBe(['email.subject', 'email.body', 'email.body', 'email.body']);
});

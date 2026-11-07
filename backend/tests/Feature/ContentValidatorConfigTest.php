<?php

declare(strict_types=1);

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Enums\Channel;

it('reads the limits from config/delivery.php', function (): void {
    $sms = fn (int $length): MessageContent => new MessageContent(Channel::Sms, null, str_repeat('a', $length));

    expect(app(ContentValidator::class)->validate($sms(160), []))->toBeNull()
        ->and(app(ContentValidator::class)->validate($sms(161), []))->not->toBeNull();

    config(['delivery.limits.sms_body_max' => 10]);

    expect(app(ContentValidator::class)->validate($sms(10), []))->toBeNull()
        ->and(app(ContentValidator::class)->validate($sms(11), [])->errors[0]['message'])->toContain('limit is 10');
});

it('reads the email limits from config too', function (): void {
    config(['delivery.limits.email_subject_max' => 5, 'delivery.limits.email_body_max' => 7]);
    $email = new MessageContent(Channel::Email, 'abcdef', 'abcdefgh');

    $paths = array_column(app(ContentValidator::class)->validate($email, [])->errors, 'path');

    expect($paths)->toBe(['email.subject', 'email.body']);
});

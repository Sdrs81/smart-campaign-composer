<?php

declare(strict_types=1);

namespace App\Content;

use App\Enums\Channel;
use App\Segment\ValidationErrors;

/**
 * The one check for message text, used for model output and for a person's edit.
 * Length is the worst case the recipient can see: visible characters after filling each variable
 * with the audience's longest value (or its fallback, if that is longer).
 */
final class ContentValidator
{
    public function __construct(
        private readonly PersonalizationRenderer $renderer,
        private readonly int $smsBodyMax,
        private readonly int $emailSubjectMax,
        private readonly int $emailBodyMax,
    ) {}

    /**
     * @param  array<string, int>  $longestValues  variable name => longest value among the customers who get this channel
     * @return ValidationErrors|null null when the content is valid; otherwise every problem, path first (e.g. "sms.body")
     */
    public function validate(MessageContent $content, array $longestValues): ?ValidationErrors
    {
        $errors = [];

        match ($content->channel) {
            Channel::Sms => $this->checkSms($content, $longestValues, $errors),
            Channel::Email => $this->checkEmail($content, $longestValues, $errors),
        };

        return $errors === [] ? null : new ValidationErrors($errors);
    }

    /**
     * @param  array<string, int>  $longestValues
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function checkSms(MessageContent $content, array $longestValues, array &$errors): void
    {
        if ($content->subject !== null) {
            $errors[] = ['path' => 'sms.subject', 'message' => 'an SMS has no subject; leave it out.'];
        }

        $this->checkText('sms.body', $content->body, $this->smsBodyMax, $longestValues, $errors);
    }

    /**
     * @param  array<string, int>  $longestValues
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function checkEmail(MessageContent $content, array $longestValues, array &$errors): void
    {
        $this->checkText('email.subject', $content->subject ?? '', $this->emailSubjectMax, $longestValues, $errors);
        $this->checkText('email.body', $content->body, $this->emailBodyMax, $longestValues, $errors);
    }

    /**
     * @param  array<string, int>  $longestValues
     * @param  list<array{path: string, message: string}>  $errors
     */
    private function checkText(string $path, string $text, int $max, array $longestValues, array &$errors): void
    {
        if (trim($text) === '') {
            $errors[] = ['path' => $path, 'message' => 'must not be empty.'];

            return;
        }

        $unknown = $this->renderer->unknownVariables($text);
        if ($unknown !== []) {
            $errors[] = ['path' => $path, 'message' => 'unknown variable '.implode(', ', array_map(fn (string $name): string => '{{'.$name.'}}', $unknown))
                .'; allowed: '.implode(', ', array_map(fn (string $name): string => '{{'.$name.'}}', Variables::names())).'.'];
        }

        if ($this->renderer->hasMalformedBraces($text)) {
            $errors[] = ['path' => $path, 'message' => "has a malformed '{{' or '}}'; write a variable as {{first_name}} with both braces and no spaces in its name."];
        }

        $length = $this->renderer->worstCaseLength($text, $longestValues);
        if ($length > $max) {
            $errors[] = ['path' => $path, 'message' => "is up to {$length} characters once the longest customer values are filled in; the limit is {$max}."];
        }
    }
}

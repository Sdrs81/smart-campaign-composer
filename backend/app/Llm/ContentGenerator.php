<?php

declare(strict_types=1);

namespace App\Llm;

use App\Content\ContentValidator;
use App\Content\MessageContent;
use App\Enums\Channel;
use App\Llm\Exceptions\LlmException;
use App\Llm\Prompts\ContentPrompt;
use App\Segment\AudienceSummary;
use Generator;
use JsonException;

/**
 * Brief → validated SMS and email text, as a stream of events (K11, K12). The first answer is streamed so
 * the marketer sees it appear; it is then parsed and checked by ContentValidator. If it fails, the problems
 * go back to the model in up to $maxRepairAttempts non-streamed repair rounds. The consumer ends with one
 * `final` or `error` event.
 *
 * Stopping early (a closed browser tab) needs no code here: when the consumer drops this generator, PHP
 * runs the `finally` blocks of the inner stream and releases its connection. Nothing in this class may
 * yield from a `finally` block, since that raises an Error in a generator that was closed that way.
 */
final class ContentGenerator
{
    private const PURPOSE = 'content';

    // The user message wraps these in tags, so text carrying the same tags could end the wrapper early.
    private const PROMPT_TAG_PATTERN = '/<\/?(brief|tone|audience)\b/i';

    public function __construct(
        private readonly LlmClient $client,
        private readonly ContentPrompt $prompt,
        private readonly ContentValidator $validator,
        private readonly int $maxRepairAttempts,
        private readonly int $maxTokens,
    ) {}

    /** @return Generator<int, ContentEvent> */
    public function generate(string $brief, string $tone, AudienceSummary $audience, string $ruleExplanation): Generator
    {
        if (preg_match(self::PROMPT_TAG_PATTERN, "{$brief}\n{$tone}\n{$ruleExplanation}") === 1) {
            yield ContentEvent::failed('content_input_invalid', 'The brief, tone and audience description must not contain <brief>, <tone> or <audience> tags.');

            return;
        }

        $messages = [['role' => 'user', 'content' => $this->prompt->userMessage($brief, $tone, $audience, $ruleExplanation)]];
        $text = '';

        try {
            foreach ($this->client->stream($this->request($messages, 1)) as $chunk) {
                if ($chunk->text !== '') {
                    $text .= $chunk->text;
                    yield ContentEvent::delta($chunk->text);
                }
            }
        } catch (LlmException $failure) {
            yield ContentEvent::failed('llm_error', $failure->getMessage());

            return;
        }

        $errors = [];
        for ($attempt = 1; ; $attempt++) {
            $outcome = $this->evaluate($text, $audience, repaired: $attempt > 1);
            if ($outcome instanceof ContentEvent) {
                yield $outcome;

                return;
            }

            $errors = $outcome;
            $this->markInvalid($errors);
            if ($attempt > $this->maxRepairAttempts) {
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $text === '' ? '(empty reply)' : $text];
            $messages[] = ['role' => 'user', 'content' => $this->repairMessage($errors)];

            try {
                $text = $this->client->complete($this->request($messages, $attempt + 1))->text;
            } catch (LlmException $failure) {
                yield ContentEvent::failed('llm_error', $failure->getMessage());

                return;
            }
        }

        yield ContentEvent::failed(
            'content_invalid',
            "The model's text was still invalid after {$attempt} attempts; last problem: {$errors[0]}",
            $errors,
        );
    }

    /** @param  list<array{role: 'user'|'assistant', content: string}>  $messages */
    private function request(array $messages, int $attempt): LlmRequest
    {
        return new LlmRequest(
            $this->prompt->system(),
            $messages,
            $this->maxTokens,
            $this->prompt->jsonSchema(),
            self::PURPOSE,
            ContentPrompt::VERSION,
            $attempt,
        );
    }

    /** @return ContentEvent|non-empty-list<string> the `final` event, or the problems to send back */
    private function evaluate(string $text, AudienceSummary $audience, bool $repaired): ContentEvent|array
    {
        try {
            $answer = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ["response: is not valid JSON ({$exception->getMessage()}). Reply with one JSON object only, without any text around it."];
        }

        if (! is_array($answer) || array_is_list($answer)) {
            return ['response: must be a JSON object with sms and email.'];
        }

        $errors = [];
        $smsBody = $this->stringAt($answer, 'sms', 'body', $errors);
        $subject = $this->stringAt($answer, 'email', 'subject', $errors);
        $emailBody = $this->stringAt($answer, 'email', 'body', $errors);
        if ($errors !== [] || $smsBody === null || $subject === null || $emailBody === null) {
            return $errors;
        }

        $sms = new MessageContent(Channel::Sms, null, $smsBody);
        $email = new MessageContent(Channel::Email, $subject, $emailBody);
        $problems = [
            ...($this->validator->validate($sms, $audience->longestValues[Channel::Sms->value] ?? [])?->messages() ?? []),
            ...($this->validator->validate($email, $audience->longestValues[Channel::Email->value] ?? [])?->messages() ?? []),
        ];

        return $problems === [] ? ContentEvent::completed($sms, $email, $repaired) : $problems;
    }

    /**
     * @param  array<mixed>  $answer
     * @param  list<string>  $errors
     */
    private function stringAt(array $answer, string $channel, string $field, array &$errors): ?string
    {
        $value = is_array($answer[$channel] ?? null) ? ($answer[$channel][$field] ?? null) : null;
        if (is_string($value)) {
            return $value;
        }

        $errors[] = "{$channel}.{$field}: must be a string.";

        return null;
    }

    /** @param  non-empty-list<string>  $errors */
    private function repairMessage(array $errors): string
    {
        return "Your previous answer was rejected by the validator:\n- ".implode("\n- ", $errors)
            ."\nFix every problem and reply with the complete corrected JSON object only.";
    }

    /** @param  non-empty-list<string>  $errors */
    private function markInvalid(array $errors): void
    {
        // Only the decorator keeps a call log; a plain client has nothing to update.
        if ($this->client instanceof RecordingLlmClient) {
            $this->client->markLastCallInvalid($errors);
        }
    }
}

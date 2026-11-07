<?php

declare(strict_types=1);

namespace App\Llm;

use App\Llm\Exceptions\SegmentExtractionFailed;
use App\Llm\Prompts\SegmentPrompt;
use App\Segment\SegmentRuleParser;
use App\Segment\ValidationErrors;
use Carbon\CarbonInterface;
use JsonException;

/**
 * Brief → validated SegmentRule (K11). The model's answer is only ever input to SegmentRuleParser, so
 * nothing in a brief, however it is phrased, can produce a rule the parser rejects. Validation errors
 * go back to the model for at most $maxRepairAttempts repair rounds. Transport failures
 * (LlmUnavailable, LlmTruncated, LlmRefused, LlmBadResponse) are not repairable and pass through.
 */
final class SegmentExtractor
{
    private const PURPOSE = 'segment';

    private const UNSUPPORTED_MAX_LENGTH = 200;

    private const EXPLANATION_MAX_LENGTH = 600;

    // A brief must not close the <brief> wrapper early and write instructions outside it.
    private const BRIEF_TAG_PATTERN = '/<\/?brief\b/i';

    public function __construct(
        private readonly LlmClient $client,
        private readonly SegmentPrompt $prompt,
        private readonly SegmentRuleParser $parser,
        private readonly int $maxRepairAttempts,
        private readonly int $maxTokens,
        private readonly int $briefMinLength,
        private readonly int $briefMaxLength,
    ) {}

    public function extract(string $brief, CarbonInterface $today): SegmentExtraction
    {
        $brief = trim($brief);
        $this->assertBriefAcceptable($brief);

        $messages = [['role' => 'user', 'content' => $this->prompt->userMessage($brief, $today)]];
        $totalAttempts = 1 + $this->maxRepairAttempts;
        $errors = [];

        for ($attempt = 1; $attempt <= $totalAttempts; $attempt++) {
            $response = $this->client->complete(new LlmRequest(
                $this->prompt->system(),
                $messages,
                $this->maxTokens,
                $this->prompt->jsonSchema(),
                self::PURPOSE,
                SegmentPrompt::VERSION,
                $attempt,
            ));

            $outcome = $this->read($response->text, $attempt);
            if ($outcome instanceof SegmentExtraction) {
                return $outcome;
            }

            $errors = $outcome;
            $this->markInvalid($errors);
            $messages[] = ['role' => 'assistant', 'content' => $response->text === '' ? '(empty reply)' : $response->text];
            $messages[] = ['role' => 'user', 'content' => $this->repairMessage($errors)];
        }

        throw new SegmentExtractionFailed(
            "The model's answer was still invalid after {$totalAttempts} attempts; last problem: {$errors[0]}",
            $errors,
            [],
            $totalAttempts,
        );
    }

    private function assertBriefAcceptable(string $brief): void
    {
        $length = mb_strlen($brief);
        $problem = match (true) {
            $length < $this->briefMinLength || $length > $this->briefMaxLength => "must be {$this->briefMinLength} to {$this->briefMaxLength} characters, got {$length}.",
            preg_match(self::BRIEF_TAG_PATTERN, $brief) === 1 => 'must not contain <brief> tags.',
            default => null,
        };

        if ($problem !== null) {
            throw new SegmentExtractionFailed("The brief {$problem}", ["brief: {$problem}"], [], 0);
        }
    }

    /** @return SegmentExtraction|non-empty-list<string> the extraction, or the problems to send back */
    private function read(string $text, int $attempt): SegmentExtraction|array
    {
        try {
            $answer = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ["response: is not valid JSON ({$exception->getMessage()}). Reply with one JSON object only, without any text around it."];
        }

        if (! is_array($answer) || array_is_list($answer)) {
            return ['response: must be a JSON object with rule, unsupported_criteria and explanation.'];
        }

        $errors = [];
        $unsupported = $this->readUnsupported($answer['unsupported_criteria'] ?? null, $errors);
        $explanation = $this->readExplanation($answer['explanation'] ?? null, $errors);
        $rule = $answer['rule'] ?? null;

        if (! is_array($rule)) {
            return [...$errors, 'rule: must be an object with version and conditions.'];
        }

        // The model saying "nothing here can be expressed" is a complete answer, not a mistake to repair.
        if (($rule['conditions'] ?? null) === [] && $unsupported !== []) {
            throw new SegmentExtractionFailed(
                'Nothing in the brief could be expressed as a segment condition.',
                ['conditions: no condition could be built from the brief.'],
                $unsupported,
                $attempt,
            );
        }

        $parsed = $this->parser->parse($rule);
        if ($parsed instanceof ValidationErrors) {
            return [...$errors, ...$parsed->messages()];
        }

        if ($errors !== []) {
            return $errors;
        }

        return new SegmentExtraction($parsed, $unsupported, $explanation, $attempt);
    }

    /**
     * @param  list<string>  $errors
     * @return list<string>
     */
    private function readUnsupported(mixed $value, array &$errors): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            $errors[] = 'unsupported_criteria: must be a list of strings (an empty list when everything was expressed).';

            return [];
        }

        $phrases = array_map(fn (string $phrase): string => mb_strimwidth(trim($phrase), 0, self::UNSUPPORTED_MAX_LENGTH, '…'), $value);

        return array_values(array_filter($phrases, fn (string $phrase): bool => $phrase !== ''));
    }

    /** @param  list<string>  $errors */
    private function readExplanation(mixed $value, array &$errors): string
    {
        if (! is_string($value) || trim($value) === '') {
            $errors[] = 'explanation: must be a non-empty string.';

            return '';
        }

        return mb_strimwidth(trim($value), 0, self::EXPLANATION_MAX_LENGTH, '…');
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

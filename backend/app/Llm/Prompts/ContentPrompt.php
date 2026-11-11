<?php

declare(strict_types=1);

namespace App\Llm\Prompts;

use App\Content\Variables;
use App\Enums\Channel;
use App\Segment\AudienceSummary;

/**
 * Builds the prompt that asks for the campaign's SMS and email text. The fixed rules, limits
 * and variable list are the system prompt; everything that changes per campaign is the user message,
 * so the system prompt stays byte-identical between calls and can be cached.
 */
final class ContentPrompt
{
    public const VERSION = 'content-v1';

    // Built from __DIR__ rather than resource_path() so unit tests need no framework boot.
    private const TEMPLATE_PATH = __DIR__.'/../../../resources/prompts/content.v1.md';

    public function __construct(
        private readonly int $smsBodyMax,
        private readonly int $emailSubjectMax,
        private readonly int $emailBodyMax,
    ) {}

    public function system(): string
    {
        return strtr((string) file_get_contents(self::TEMPLATE_PATH), [
            '%SMS_BODY_MAX%' => (string) $this->smsBodyMax,
            '%EMAIL_SUBJECT_MAX%' => (string) $this->emailSubjectMax,
            '%EMAIL_BODY_MAX%' => (string) $this->emailBodyMax,
            '%VARIABLES%' => $this->variableList(),
        ]);
    }

    /**
     * @param  string  $ruleExplanation  how the audience was read from the brief (SegmentExtraction::$explanation)
     */
    public function userMessage(string $brief, string $tone, AudienceSummary $audience, string $ruleExplanation): string
    {
        $reachable = array_map(
            fn (Channel $channel): string => "- {$channel->value}: ".($audience->channelCounts[$channel->value] ?? 0).' customers can be reached',
            Channel::cases(),
        );

        return "<brief>{$brief}</brief>\n\n"
            ."<tone>{$tone}</tone>\n\n"
            ."<audience>\n"
            .implode("\n", $reachable)."\n"
            ."- {$audience->uniqueTotal} different customers in total\n"
            ."- how the audience was chosen: {$ruleExplanation}\n"
            ."</audience>\n\n"
            ."Longest customer values (leave room for them):\n"
            .implode("\n", array_map(fn (Channel $channel): string => $this->longestValuesLine($channel, $audience), Channel::cases()));
    }

    /**
     * Structured outputs reject size constraints, so lengths are enforced by ContentValidator and the repair round.
     *
     * @return array<string, mixed>
     */
    public function jsonSchema(): array
    {
        $text = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'sms' => [
                    'type' => 'object',
                    'properties' => ['body' => $text],
                    'required' => ['body'],
                    'additionalProperties' => false,
                ],
                'email' => [
                    'type' => 'object',
                    'properties' => ['subject' => $text, 'body' => $text],
                    'required' => ['subject', 'body'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['sms', 'email'],
            'additionalProperties' => false,
        ];
    }

    private function variableList(): string
    {
        return implode("\n", array_map(
            fn (string $name): string => '- `{{'.$name.'}}` → "'.Variables::fallback($name).'"',
            Variables::names(),
        ));
    }

    private function longestValuesLine(Channel $channel, AudienceSummary $audience): string
    {
        $values = array_map(
            fn (string $name): string => '{{'.$name.'}} up to '.($audience->longestValues[$channel->value][$name] ?? 0).' characters',
            Variables::names(),
        );

        return "- {$channel->value}: ".implode(', ', $values);
    }
}

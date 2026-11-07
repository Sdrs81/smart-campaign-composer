<?php

declare(strict_types=1);

namespace App\Llm\Fake;

use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\LlmUsage;
use LogicException;

/**
 * Ready-made answers for running the app without an API key (K9). The scenario is chosen by keywords in
 * the brief, from the fixtures in resources/llm-fixtures; a brief that matches none gets the generic one.
 * Fixtures are read in file-name order and the first keyword match wins.
 */
final class DevScenarios
{
    // The pause between streamed pieces, so the text visibly appears instead of arriving at once.
    public const STREAM_DELAY_MIN_MS = 20;

    public const STREAM_DELAY_MAX_MS = 40;

    private const FIXTURES_DIRECTORY = __DIR__.'/../../../resources/llm-fixtures';

    private const BRIEF_PATTERN = '#<brief>(.*?)</brief>#s';

    // A rough size for the usage the call log shows; the real count comes from a real model.
    private const CHARACTERS_PER_TOKEN = 4;

    private const TURKISH_TO_ASCII = [
        'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'ş' => 's', 'Ş' => 's', 'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u', 'ö' => 'o', 'Ö' => 'o', 'ç' => 'c', 'Ç' => 'c',
    ];

    /** @var list<array{name: string, keywords: list<string>, segment: array<string, mixed>, content: array<string, mixed>}>|null */
    private ?array $scenarios = null;

    public function respond(LlmRequest $request): LlmResponse
    {
        $scenario = $this->scenarioFor($this->briefOf($request));
        $answer = match ($request->purpose) {
            'segment' => $scenario['segment'],
            'content' => $scenario['content'],
            default => throw new LogicException("DevScenarios has no answer for purpose '{$request->purpose}'; supported: segment, content."),
        };
        $text = json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new LlmResponse($text, new LlmUsage($this->tokensOf($request), $this->estimateTokens($text)));
    }

    public function scenarioNameFor(string $brief): string
    {
        return $this->scenarioFor($brief)['name'];
    }

    /** @return array{name: string, keywords: list<string>, segment: array<string, mixed>, content: array<string, mixed>} */
    private function scenarioFor(string $brief): array
    {
        $folded = $this->fold($brief);
        $generic = null;

        foreach ($this->scenarios() as $scenario) {
            if ($scenario['keywords'] === []) {
                $generic = $scenario;

                continue;
            }
            foreach ($scenario['keywords'] as $keyword) {
                if (str_contains($folded, $keyword)) {
                    return $scenario;
                }
            }
        }

        return $generic ?? throw new LogicException('No generic fixture (one with "keywords": []) in '.self::FIXTURES_DIRECTORY.'.');
    }

    /** The brief is in the first user message; later messages of a repair round repeat other text. */
    private function briefOf(LlmRequest $request): string
    {
        preg_match(self::BRIEF_PATTERN, $request->messages[0]['content'] ?? '', $match);

        return $match[1] ?? '';
    }

    /**
     * mb_strtolower ignores the Turkish rule that I lowercases to ı and İ to i, so the text is folded to
     * plain ASCII first and compared that way.
     */
    private function fold(string $text): string
    {
        return strtolower(strtr($text, self::TURKISH_TO_ASCII));
    }

    /** @return list<array{name: string, keywords: list<string>, segment: array<string, mixed>, content: array<string, mixed>}> */
    private function scenarios(): array
    {
        if ($this->scenarios !== null) {
            return $this->scenarios;
        }

        $files = glob(self::FIXTURES_DIRECTORY.'/*.json') ?: [];
        sort($files);
        $this->scenarios = array_map(function (string $file): array {
            $scenario = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $scenario['keywords'] = array_map($this->fold(...), $scenario['keywords']);

            return $scenario;
        }, $files);

        return $this->scenarios;
    }

    private function tokensOf(LlmRequest $request): int
    {
        return $this->estimateTokens($request->system.json_encode($request->messages, JSON_UNESCAPED_UNICODE));
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARACTERS_PER_TOKEN);
    }
}

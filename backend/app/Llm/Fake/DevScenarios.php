<?php

declare(strict_types=1);

namespace App\Llm\Fake;

use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\LlmUsage;
use App\Segment\SegmentRule;
use LogicException;

/**
 * Ready-made answers for running the app without an API key (K9). The scenario is chosen by keywords in
 * the brief, from the fixtures in resources/llm-fixtures; a brief that matches none gets the generic one.
 * Fixtures are read in file-name order and the first keyword match wins.
 *
 * @phpstan-type Scenario array{name: string, keywords: list<string>, segment: array<string, mixed>, content: list<array{offer: string, sms: array{body: string}, email: array{subject: string, body: string}}>}
 */
final class DevScenarios
{
    // The pause between streamed pieces, so the text visibly appears instead of arriving at once.
    public const STREAM_DELAY_MIN_MS = 20;

    public const STREAM_DELAY_MAX_MS = 40;

    private const FIXTURES_DIRECTORY = __DIR__.'/../../../resources/llm-fixtures';

    private const BRIEF_PATTERN = '#<brief>(.*?)</brief>#s';

    // Two names keep an SMS inside its limit; a brief naming more gets the first two.
    private const MAX_CATEGORIES_NAMED = 2;

    // Reads naturally in "%CATEGORY% kategorisinde" when the brief names no category.
    private const NO_CATEGORY_NAMED = 'seçili ürün';

    // A rough size for the usage the call log shows; the real count comes from a real model.
    private const CHARACTERS_PER_TOKEN = 4;

    /** @var list<Scenario>|null */
    private ?array $scenarios = null;

    /** @param  BriefReader|null  $reader  adds the filters the brief states itself (city, age, ...); without it the scenario's own rule is used */
    public function __construct(private readonly ?BriefReader $reader = null) {}

    public function respond(LlmRequest $request): LlmResponse
    {
        $brief = $this->briefOf($request);
        $scenario = $this->scenarioFor($brief);
        $answer = match ($request->purpose) {
            'segment' => $this->segmentFor($scenario, $brief),
            'content' => $this->contentVariant($brief),
            default => throw new LogicException("DevScenarios has no answer for purpose '{$request->purpose}'; supported: segment, content."),
        };
        $text = json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new LlmResponse($text, new LlmUsage($this->tokensOf($request), $this->estimateTokens($text)));
    }

    public function scenarioNameFor(string $brief): string
    {
        return $this->scenarioFor($brief)['name'];
    }

    public function variantCount(string $brief): int
    {
        return count($this->scenarioFor($brief)['content']);
    }

    /**
     * One ready-made SMS + email pair of the brief's scenario. Both texts are written with the same
     * %OFFER% slot, filled once from the brief (discount, single city, categories), so the key facts are
     * the same in the SMS and the email by construction (K25). A fact the brief does not state falls back
     * to the variant's own default offer; city names are never written into the fixtures.
     *
     * @return array{sms: array{body: string}, email: array{subject: string, body: string}}
     */
    public function contentVariant(string $brief, int $variant = 0): array
    {
        $variants = $this->scenarioFor($brief)['content'];
        $chosen = $variants[$variant % count($variants)];
        $facts = $this->reader?->facts($brief) ?? ['discount' => null, 'city' => null, 'categories' => []];

        $offer = $facts['discount'] !== null ? "%{$facts['discount']} indirim" : $chosen['offer'];
        if ($facts['city'] !== null) {
            $offer = "{$facts['city']} için {$offer}";
        }
        $category = implode(' ve ', array_slice($facts['categories'], 0, self::MAX_CATEGORIES_NAMED)) ?: self::NO_CATEGORY_NAMED;

        $fill = fn (string $text): string => strtr($text, ['%OFFER%' => $offer, '%CATEGORY%' => $category]);

        return [
            'sms' => ['body' => $fill($chosen['sms']['body'])],
            'email' => ['subject' => $fill($chosen['email']['subject']), 'body' => $fill($chosen['email']['body'])],
        ];
    }

    /**
     * The scenario's conditions, with what the brief states itself laid over them field by field: the brief
     * wins on a shared field, and the generic scenario's "everyone" condition is dropped once the brief
     * names a real filter.
     *
     * @param  Scenario  $scenario
     * @return array<string, mixed>
     */
    private function segmentFor(array $scenario, string $brief): array
    {
        $read = $this->reader?->read($brief) ?? [];
        $segment = $scenario['segment'];
        if ($read === []) {
            return $segment;
        }

        $conditions = $scenario['keywords'] === [] ? [] : $segment['rule']['conditions'];
        foreach ($read as $condition) {
            $position = array_search($condition['field'], array_column($conditions, 'field'), true);
            if ($position === false) {
                $conditions[] = $condition;
            } else {
                $conditions[$position] = $condition;
            }
        }
        $conditions = array_slice($conditions, 0, SegmentRule::MAX_CONDITIONS);

        $segment['rule']['conditions'] = $conditions;
        $segment['explanation'] = $this->reader->describe($conditions);

        return $segment;
    }

    /** @return Scenario */
    private function scenarioFor(string $brief): array
    {
        $folded = BriefReader::fold($brief);
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

    /** @return list<Scenario> */
    private function scenarios(): array
    {
        if ($this->scenarios !== null) {
            return $this->scenarios;
        }

        $files = glob(self::FIXTURES_DIRECTORY.'/*.json') ?: [];
        sort($files);
        $this->scenarios = array_map(function (string $file): array {
            $scenario = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            $scenario['keywords'] = array_map(BriefReader::fold(...), $scenario['keywords']);

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

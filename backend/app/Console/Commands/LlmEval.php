<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Llm\ContentEvent;
use App\Llm\ContentGenerator;
use App\Llm\Exceptions\SegmentExtractionFailed;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Llm\SegmentExtraction;
use App\Llm\SegmentExtractor;
use App\Segment\AudienceSummary;
use App\Segment\SegmentRule;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use LogicException;
use Throwable;

/**
 * Runs the golden briefs through the same SegmentExtractor and ContentGenerator the app uses, with the
 * selected driver, and reports validity, attempts and latency.
 *
 * With the fake driver the golden file's scripted ideal answers are queued, so a run checks the pipeline
 * and the expectations (an oracle), not a model. With a key it measures the real model.
 *
 * Verdicts: `pass`; `fail` (the model answered, but not validly or not as expected); `error` (the call
 * itself broke, for example the API was unavailable, which says nothing about the model and is left
 * out of the rates).
 *
 * @phpstan-type Result array{id: string, verdict: 'pass'|'fail'|'error', attempts: int|null, repaired: bool|null, segment_ms: int, content_ms: int, valid: bool, claims_met: bool, problems: list<string>}
 */
final class LlmEval extends Command
{
    protected $signature = 'llm:eval
                            {--only= : Run only the brief with this id}
                            {--file= : Golden briefs file (default resources/llm-eval/briefs.json)}';

    protected $description = 'Run the golden briefs through the segment extractor and content generator and report validity, attempts and latency';

    private const DEFAULT_FILE = 'resources/llm-eval/briefs.json';

    private const NANOSECONDS_PER_MILLISECOND = 1_000_000;

    // A fixed audience, so every run sees the same prompt and the same limits (the longest values of a typical customer base).
    private const AUDIENCE_CUSTOMERS = 1000;

    private const LONGEST_VALUES = ['first_name' => 20, 'last_name' => 20, 'city' => 15];

    public function handle(): int
    {
        $golden = $this->loadGolden();
        $briefs = $this->select($golden['briefs']);
        if ($briefs === []) {
            return self::FAILURE;
        }

        $scripted = Config::string('llm.driver') === 'fake';
        if ($scripted) {
            // The ready-made dev answers would hide a scripted answer that is missing.
            config(['llm.fake.dev_scenarios' => false]);
        }
        $fake = $scripted ? $this->scriptedClient($this->laravel->make(LlmClient::class)) : null;
        $this->announce($scripted, count($briefs));

        $extractor = app(SegmentExtractor::class);
        $generator = app(ContentGenerator::class);
        $results = [];
        foreach ($briefs as $brief) {
            $results[] = $this->evaluate($brief, $golden, $fake, $extractor, $generator);
        }

        $this->report($results);

        return array_filter($results, fn (array $result): bool => $result['verdict'] !== 'pass') === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{tone: string, fake_content: array<string, mixed>, briefs: list<array<string, mixed>>} */
    private function loadGolden(): array
    {
        $file = $this->option('file') ?: base_path(self::DEFAULT_FILE);

        return json_decode((string) file_get_contents((string) $file), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $briefs
     * @return list<array<string, mixed>> empty (after printing why) when --only names no brief
     */
    private function select(array $briefs): array
    {
        $only = $this->option('only');
        if ($only === null || $only === '') {
            return $briefs;
        }

        $selected = array_values(array_filter($briefs, fn (array $brief): bool => $brief['id'] === $only));
        if ($selected === []) {
            $this->error("No brief with id '{$only}'; available: ".implode(', ', array_column($briefs, 'id')).'.');
        }

        return $selected;
    }

    // Takes the interface type on purpose: Larastan resolves the container binding with the analysis
    // environment's driver, so a plain app(LlmClient::class) would look like it is always the fake.
    private function scriptedClient(LlmClient $client): FakeLlmClient
    {
        return $client instanceof FakeLlmClient ? $client : throw new LogicException('LLM_DRIVER=fake but the bound client is '.get_debug_type($client).'.');
    }

    private function announce(bool $scripted, int $count): void
    {
        $this->line($scripted
            ? "Driver: fake. Scripted ideal answers, so this checks the pipeline and the expectations, not a model. ({$count} briefs)"
            : 'Driver: claude ('.Config::string('llm.anthropic.model')."). Real API calls: 2 per brief, {$count} briefs.");
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array{tone: string, fake_content: array<string, mixed>, briefs: list<array<string, mixed>>}  $golden
     * @return Result
     */
    private function evaluate(array $brief, array $golden, ?FakeLlmClient $fake, SegmentExtractor $extractor, ContentGenerator $generator): array
    {
        $result = ['id' => $brief['id'], 'verdict' => 'pass', 'attempts' => null, 'repaired' => null, 'segment_ms' => 0, 'content_ms' => 0, 'valid' => false, 'claims_met' => false, 'problems' => []];

        try {
            $fake?->enqueue(json_encode($brief['fake_segment'], JSON_THROW_ON_ERROR));
            $started = hrtime(true);
            $outcome = $this->extract($extractor, $brief['brief']);
            $result['segment_ms'] = $this->elapsedMs($started);

            if (($brief['expect']['outcome'] ?? 'rule') === 'unsupported_only') {
                return $this->judgeUnsupportedOnly($result, $outcome);
            }
            if ($outcome instanceof SegmentExtractionFailed) {
                return $this->failed($result, $outcome->details()['errors']);
            }

            $result['attempts'] = $outcome->attempts;
            $claimProblems = $this->claimProblems($outcome, $brief['expect']);
            $result['claims_met'] = $claimProblems === [];

            $fake?->enqueue(json_encode($golden['fake_content'], JSON_THROW_ON_ERROR));
            $started = hrtime(true);
            $last = $this->lastContentEvent($generator, $brief['brief'], $golden['tone'], $outcome->explanation);
            $result['content_ms'] = $this->elapsedMs($started);

            if ($last->type === ContentEvent::FAILED && $last->code === 'llm_error') {
                return $this->broken($result, $last->message);
            }
            $result['repaired'] = $last->repaired;
            $result['valid'] = $last->type === ContentEvent::COMPLETED;
            $contentProblems = $result['valid'] ? [] : ["content: {$last->message}", ...$last->errors];

            return $this->failed($result, [...$claimProblems, ...$contentProblems]);
        } catch (Throwable $failure) {
            return $this->broken($result, class_basename($failure).': '.$failure->getMessage());
        }
    }

    private function extract(SegmentExtractor $extractor, string $brief): SegmentExtraction|SegmentExtractionFailed
    {
        try {
            return $extractor->extract($brief, CarbonImmutable::now());
        } catch (SegmentExtractionFailed $failure) {
            return $failure;
        }
    }

    private function lastContentEvent(ContentGenerator $generator, string $brief, string $tone, string $explanation): ContentEvent
    {
        $audience = new AudienceSummary(
            ['sms' => self::AUDIENCE_CUSTOMERS, 'email' => self::AUDIENCE_CUSTOMERS],
            self::AUDIENCE_CUSTOMERS,
            [],
            ['sms' => self::LONGEST_VALUES, 'email' => self::LONGEST_VALUES],
        );
        $last = null;
        foreach ($generator->generate($brief, $tone, $audience, $explanation) as $event) {
            $last = $event;
        }

        return $last ?? throw new LogicException('ContentGenerator produced no event.');
    }

    /**
     * "Nothing can be expressed" is a right answer here, so it must come as that: a rejection that lists
     * unsupported criteria. A rule, or a failure for another reason, is a miss.
     *
     * @param  Result  $result
     * @return Result
     */
    private function judgeUnsupportedOnly(array $result, SegmentExtraction|SegmentExtractionFailed $outcome): array
    {
        if ($outcome instanceof SegmentExtraction) {
            return $this->failed($result, ['expected no condition to be expressible, but a rule was returned.']);
        }
        if ($outcome->details()['unsupported_criteria'] === []) {
            return $this->failed($result, ['rejected without listing what could not be expressed: '.$outcome->getMessage()]);
        }

        $result['attempts'] = $outcome->details()['attempts'];
        $result['valid'] = true;
        $result['claims_met'] = true;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $expect
     * @return list<string>
     */
    private function claimProblems(SegmentExtraction $extraction, array $expect): array
    {
        $problems = [];
        $claims = $expect['conditions'];

        if (count($extraction->rule->conditions) !== count($claims)) {
            $problems[] = 'expected '.count($claims).' conditions, got '.count($extraction->rule->conditions).'.';
        }
        foreach ($claims as $claim) {
            if (! $this->claimMet($claim, $extraction->rule)) {
                $problems[] = 'missing condition '.$this->describe($claim).'.';
            }
        }
        if ($expect['unsupported'] === true && $extraction->unsupported === []) {
            $problems[] = 'expected an unsupported criterion, none was reported.';
        }
        if ($expect['unsupported'] === false && $extraction->unsupported !== []) {
            $problems[] = 'reported unsupported criteria that should have been expressible: '.implode('; ', $extraction->unsupported).'.';
        }

        return $problems;
    }

    /** @param  array<string, mixed>  $claim  one condition, or {any_of: [conditions]} when several answers are equally right */
    private function claimMet(array $claim, SegmentRule $rule): bool
    {
        foreach ($claim['any_of'] ?? [] as $alternative) {
            if ($this->claimMet($alternative, $rule)) {
                return true;
            }
        }

        foreach ($rule->conditions as $condition) {
            // Loose comparison on purpose: 5000 and 5000.0 are the same amount.
            if ($condition->field->name === ($claim['field'] ?? null) && $condition->operator->value === $claim['operator'] && $condition->value == $claim['value']) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $claim */
    private function describe(array $claim): string
    {
        if (isset($claim['any_of'])) {
            return implode(' OR ', array_map(fn (array $alternative): string => $this->describe($alternative), $claim['any_of']));
        }

        return "{$claim['field']} {$claim['operator']} ".json_encode($claim['value'], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  Result  $result
     * @param  list<string>  $problems
     * @return Result
     */
    private function failed(array $result, array $problems): array
    {
        if ($problems !== []) {
            $result['verdict'] = 'fail';
            $result['problems'] = $problems;
        }

        return $result;
    }

    /**
     * @param  Result  $result
     * @return Result
     */
    private function broken(array $result, string $message): array
    {
        $result['verdict'] = 'error';
        $result['problems'] = [$message];

        return $result;
    }

    private function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / self::NANOSECONDS_PER_MILLISECOND);
    }

    /** @param  list<Result>  $results */
    private function report(array $results): void
    {
        $this->table(
            ['Brief', 'Attempts', 'Valid', 'Claims', 'Content', 'Segment ms', 'Content ms', 'Result'],
            array_map(fn (array $result): array => [
                $result['id'],
                $result['attempts'] ?? '-',
                $result['valid'] ? 'yes' : 'no',
                $result['claims_met'] ? 'yes' : 'no',
                $result['repaired'] === null ? '-' : ($result['repaired'] ? 'repaired' : 'first try'),
                $result['segment_ms'],
                $result['content_ms'],
                $result['verdict'],
            ], $results),
        );

        foreach ($results as $result) {
            foreach ($result['problems'] as $problem) {
                $this->line("{$result['id']}: {$problem}");
            }
        }

        $this->summary($results);
    }

    /** @param  list<Result>  $results */
    private function summary(array $results): void
    {
        $scored = array_values(array_filter($results, fn (array $result): bool => $result['verdict'] !== 'error'));
        $total = count($scored);
        $errors = count($results) - $total;
        $count = fn (string $key): int => count(array_filter($scored, fn (array $result): bool => $result[$key] === true));
        $attempts = array_filter(array_column($scored, 'attempts'), fn (?int $value): bool => $value !== null);
        $repaired = count(array_filter($scored, fn (array $result): bool => $result['repaired'] === true));
        $average = fn (string $key): string => $total === 0 ? 'n/a' : (string) round(array_sum(array_column($scored, $key)) / $total);

        $this->newLine();
        $this->line("Scored {$total} of ".count($results).' briefs'.($errors > 0 ? " ({$errors} errors left out: the call itself broke)" : '').'.');
        $this->line("Valid rule and content: {$count('valid')}/{$total} ({$this->percent($count('valid'), $total)})");
        $this->line("Expected claims met:    {$count('claims_met')}/{$total} ({$this->percent($count('claims_met'), $total)})");
        $this->line('Passed:                 '.count(array_filter($scored, fn (array $result): bool => $result['verdict'] === 'pass'))."/{$total} ({$this->percent(count(array_filter($scored, fn (array $result): bool => $result['verdict'] === 'pass')), $total)})");
        $this->line('Average segment attempts: '.($attempts === [] ? 'n/a' : number_format(array_sum($attempts) / count($attempts), 2)));
        $this->line("Content repaired: {$repaired} of {$total}");
        $this->line("Average latency: segment {$average('segment_ms')} ms, content {$average('content_ms')} ms (per brief; includes repair rounds and retries)");
    }

    private function percent(int $part, int $total): string
    {
        return $total === 0 ? 'n/a' : round($part / $total * 100).'%';
    }
}

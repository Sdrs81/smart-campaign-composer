<?php

declare(strict_types=1);

namespace App\Providers;

use App\Content\ContentValidator;
use App\Llm\ClaudeLlmClient;
use App\Llm\ContentGenerator;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Llm\Prompts\ContentPrompt;
use App\Llm\Prompts\SegmentPrompt;
use App\Llm\RecordingLlmClient;
use App\Llm\SegmentExtractor;
use App\Segment\SegmentRuleParser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LlmServiceProvider extends ServiceProvider
{
    // Recorded as the model of every call made by the offline client.
    private const FAKE_MODEL_NAME = 'fake';

    public function register(): void
    {
        // Singleton: the fake keeps its scenario queue between resolutions.
        $this->app->singleton(LlmClient::class, fn (): LlmClient => $this->resolveClient());

        // LlmClient stays the raw client so tests can queue scenarios on the fake. Classes that must
        // write the call log (K11) ask for RecordingLlmClient, which wraps that same shared instance.
        $this->app->singleton(RecordingLlmClient::class, fn (Application $app): RecordingLlmClient => new RecordingLlmClient(
            $app->make(LlmClient::class),
            config('llm.driver') === 'fake' ? self::FAKE_MODEL_NAME : (string) config('llm.anthropic.model'),
        ));

        // Built here instead of autowired: it needs the logging client and config values (K11).
        $this->app->bind(SegmentExtractor::class, fn (Application $app): SegmentExtractor => new SegmentExtractor(
            $app->make(RecordingLlmClient::class),
            $app->make(SegmentPrompt::class),
            $app->make(SegmentRuleParser::class),
            (int) config('llm.max_repair_attempts'),
            (int) config('llm.segment.max_tokens'),
            (int) config('llm.brief.min_length'),
            (int) config('llm.brief.max_length'),
        ));

        $this->app->bind(ContentGenerator::class, fn (Application $app): ContentGenerator => new ContentGenerator(
            $app->make(RecordingLlmClient::class),
            $app->make(ContentPrompt::class),
            $app->make(ContentValidator::class),
            Config::integer('llm.max_repair_attempts'),
            Config::integer('llm.content.max_tokens'),
        ));
    }

    // config/llm.php only resolves; an invalid combination fails here, loudly (K9).
    private function resolveClient(): LlmClient
    {
        $driver = (string) config('llm.driver');

        return match ($driver) {
            'fake' => new FakeLlmClient,
            'claude' => $this->claudeClient(),
            default => throw new InvalidArgumentException(
                "Unknown LLM_DRIVER '{$driver}'; allowed: auto, claude, fake."
            ),
        };
    }

    private function claudeClient(): LlmClient
    {
        if (blank(config('llm.anthropic.api_key'))) {
            throw new InvalidArgumentException(
                'LLM_DRIVER=claude requires ANTHROPIC_API_KEY; set it, or use LLM_DRIVER=auto or fake.'
            );
        }

        return new ClaudeLlmClient(
            (string) config('llm.anthropic.api_key'),
            (string) config('llm.anthropic.model'),
            (int) config('llm.anthropic.timeout'),
        );
    }
}

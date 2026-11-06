<?php

declare(strict_types=1);

namespace App\Providers;

use App\Llm\ClaudeLlmClient;
use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use App\Llm\RecordingLlmClient;
use Illuminate\Contracts\Foundation\Application;
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

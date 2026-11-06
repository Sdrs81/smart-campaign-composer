<?php

declare(strict_types=1);

namespace App\Providers;

use App\Llm\FakeLlmClient;
use App\Llm\LlmClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class LlmServiceProvider extends ServiceProvider
{
    // Replaced by ClaudeLlmClient::class in task 5.2.
    public const CLAUDE_CLIENT = 'App\Llm\ClaudeLlmClient';

    public function register(): void
    {
        // Singleton: the fake keeps its scenario queue between resolutions.
        $this->app->singleton(LlmClient::class, fn (Application $app): LlmClient => $this->resolveClient($app));
    }

    // config/llm.php only resolves; an invalid combination fails here, loudly (K9).
    private function resolveClient(Application $app): LlmClient
    {
        $driver = (string) config('llm.driver');

        return match ($driver) {
            'fake' => new FakeLlmClient,
            'claude' => $this->claudeClient($app),
            default => throw new InvalidArgumentException(
                "Unknown LLM_DRIVER '{$driver}'; allowed: auto, claude, fake."
            ),
        };
    }

    private function claudeClient(Application $app): LlmClient
    {
        if (blank(config('llm.anthropic.api_key'))) {
            throw new InvalidArgumentException(
                'LLM_DRIVER=claude requires ANTHROPIC_API_KEY; set it, or use LLM_DRIVER=auto or fake.'
            );
        }

        return $app->make(self::CLAUDE_CLIENT);
    }
}

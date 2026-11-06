<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->warnWhenLlmFallsBackToFake();
    }

    // Silent fallback would make a missing key look like a working Claude setup.
    private function warnWhenLlmFallsBackToFake(): void
    {
        if (config('llm.requested') !== 'auto' || config('llm.driver') !== 'fake') {
            return;
        }

        Log::warning('LLM_DRIVER=auto but ANTHROPIC_API_KEY is empty; using the offline fake LLM client.');
    }
}

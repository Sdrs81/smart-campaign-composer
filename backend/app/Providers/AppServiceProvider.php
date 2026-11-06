<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Customer;
use App\Segment\FieldRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const CITIES_CACHE_KEY = 'segment.cities';

    private const CITIES_CACHE_SECONDS = 3600;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FieldRegistry::class, fn (): FieldRegistry => new FieldRegistry(
            fn (): array => Cache::remember(
                self::CITIES_CACHE_KEY,
                self::CITIES_CACHE_SECONDS,
                fn (): array => Customer::query()->distinct()->orderBy('city')->pluck('city')->all(),
            ),
        ));
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

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
            fn (): array => $this->cachedCities(),
        ));
    }

    /**
     * An empty list is never cached: it would reject every city until the entry expires,
     * for example when the first call runs before the customers are seeded.
     *
     * @return list<string>
     */
    private function cachedCities(): array
    {
        $cities = Cache::get(self::CITIES_CACHE_KEY);
        if (is_array($cities)) {
            return $cities;
        }

        $cities = Customer::query()->distinct()->orderBy('city')->pluck('city')->all();
        if ($cities !== []) {
            Cache::put(self::CITIES_CACHE_KEY, $cities, self::CITIES_CACHE_SECONDS);
        }

        return $cities;
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

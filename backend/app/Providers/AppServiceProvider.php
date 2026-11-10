<?php

declare(strict_types=1);

namespace App\Providers;

use App\Content\ContentValidator;
use App\Content\PersonalizationRenderer;
use App\Delivery\ChannelRateLimiter;
use App\Delivery\HttpMessageProvider;
use App\Delivery\MessageProvider;
use App\Delivery\RetryPolicy;
use App\Delivery\SendRateLimiter;
use App\Llm\Prompts\ContentPrompt;
use App\Models\Customer;
use App\Segment\FieldRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
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

        // Limits are read when the validator is resolved, from the single config (K13). Config::integer
        // throws on a wrong type instead of casting it silently.
        $this->app->bind(ContentValidator::class, fn (Application $app): ContentValidator => new ContentValidator(
            $app->make(PersonalizationRenderer::class),
            Config::integer('delivery.limits.sms_body_max'),
            Config::integer('delivery.limits.email_subject_max'),
            Config::integer('delivery.limits.email_body_max'),
        ));

        // Both are built from config because they take scalars and a closure the container cannot guess.
        $this->app->bind(MessageProvider::class, fn (): MessageProvider => HttpMessageProvider::fromConfig());
        $this->app->bind(RetryPolicy::class, fn (): RetryPolicy => RetryPolicy::fromConfig());
        $this->app->bind(ChannelRateLimiter::class, fn (): ChannelRateLimiter => ChannelRateLimiter::fromConfig());
        // The send job asks for the interface; the metrics endpoint reads the peak from the concrete limiter.
        $this->app->bind(SendRateLimiter::class, fn (Application $app): SendRateLimiter => $app->make(ChannelRateLimiter::class));

        // The prompt quotes the same limits the validator enforces, so it reads them from the same config.
        $this->app->bind(ContentPrompt::class, fn (): ContentPrompt => new ContentPrompt(
            Config::integer('delivery.limits.sms_body_max'),
            Config::integer('delivery.limits.email_subject_max'),
            Config::integer('delivery.limits.email_body_max'),
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

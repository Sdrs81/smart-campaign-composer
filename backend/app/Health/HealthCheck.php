<?php

declare(strict_types=1);

namespace App\Health;

use App\Delivery\QueueDepths;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheck
{
    public const OK = 'ok';

    // The app still works without the provider, but sends fail and retry (K21).
    public const DEGRADED = 'degraded';

    private const UP = 'up';

    private const DOWN = 'down';

    // A slow provider must not make the health check itself slow.
    private const PROVIDER_TIMEOUT_SECONDS = 1;

    private const PROVIDER_CACHE_KEY = 'health.provider';

    // Longer than a few polls, shorter than the time a person waits to see a recovered provider.
    private const PROVIDER_CACHE_SECONDS = 15;

    public function __construct(private readonly QueueDepths $queueDepths) {}

    /**
     * `healthy` is false when a critical dependency (database, Redis) is down. The provider only degrades.
     *
     * @return array{healthy: bool, status: string, checks: array{database: string, redis: string, provider: string}, queues: array<string, int>, llm_driver: string}
     */
    public function report(): array
    {
        $checks = [
            'database' => $this->probe('database', fn () => DB::connection()->select('select 1')),
            'redis' => $this->probe('redis', fn () => Redis::connection()->ping()),
        ];
        $checks['provider'] = $this->providerState($checks['redis'] === self::UP);

        $healthy = $checks['database'] === self::UP && $checks['redis'] === self::UP;

        return [
            'healthy' => $healthy,
            'status' => $healthy && $checks['provider'] === self::UP ? self::OK : self::DEGRADED,
            'checks' => $checks,
            // Depths come from Redis, so they are only read when Redis answered.
            'queues' => $checks['redis'] === self::UP ? ($this->queueDepths)() : [],
            // Read from config only: the health check never calls the model API.
            'llm_driver' => (string) config('llm.driver'),
        ];
    }

    /**
     * A stopped provider container can stall DNS for seconds, past the HTTP timeout, so a polling
     * dashboard or the Docker health check would pay that stall on every request. The state is
     * cached briefly instead. The cache lives in Redis; when Redis is down the 503 path must not
     * depend on it, so the provider is probed directly then.
     */
    private function providerState(bool $cacheAvailable): string
    {
        $probe = fn (): string => $this->probe('provider', fn () => $this->pingProvider());

        return $cacheAvailable ? Cache::remember(self::PROVIDER_CACHE_KEY, self::PROVIDER_CACHE_SECONDS, $probe) : $probe();
    }

    private function pingProvider(): void
    {
        Http::baseUrl((string) config('delivery.provider.url'))
            ->connectTimeout(self::PROVIDER_TIMEOUT_SECONDS)
            ->timeout(self::PROVIDER_TIMEOUT_SECONDS)
            ->get('health')
            ->throw();
    }

    private function probe(string $name, callable $ping): string
    {
        try {
            $ping();

            return self::UP;
        } catch (Throwable $e) {
            Log::warning("Health check: {$name} is down: {$e->getMessage()}");

            return self::DOWN;
        }
    }
}

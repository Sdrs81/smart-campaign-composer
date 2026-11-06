<?php

declare(strict_types=1);

namespace App\Health;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheck
{
    private const UP = 'up';

    private const DOWN = 'down';

    /**
     * @return array{healthy: bool, checks: array{database: string, redis: string}, llm_driver: string}
     */
    public function report(): array
    {
        $checks = [
            'database' => $this->probe('database', fn () => DB::connection()->select('select 1')),
            'redis' => $this->probe('redis', fn () => Redis::connection()->ping()),
        ];

        return [
            'healthy' => ! in_array(self::DOWN, $checks, true),
            'checks' => $checks,
            'llm_driver' => (string) config('llm.driver'),
        ];
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

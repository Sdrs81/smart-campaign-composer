<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\DependencyUnavailableException;
use App\Health\HealthCheck;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $report = $health->report();

        if (! $report['healthy']) {
            // The provider is not critical, so it never appears in the "required" list.
            $critical = array_intersect_key($report['checks'], array_flip(['database', 'redis']));
            $down = implode(', ', array_keys(array_filter($critical, fn (string $state) => $state === 'down')));

            throw new DependencyUnavailableException("Required dependency is down: {$down}.", [
                'checks' => $report['checks'],
                'llm_driver' => $report['llm_driver'],
            ]);
        }

        return response()->json([
            'status' => $report['status'],
            'checks' => $report['checks'],
            'queues' => $report['queues'],
            'llm_driver' => $report['llm_driver'],
        ]);
    }
}

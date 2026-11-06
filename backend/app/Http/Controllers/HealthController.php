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
            $down = implode(', ', array_keys(array_filter($report['checks'], fn (string $state) => $state === 'down')));

            throw new DependencyUnavailableException("Required dependency is down: {$down}.", [
                'checks' => $report['checks'],
                'llm_driver' => $report['llm_driver'],
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'checks' => $report['checks'],
            'llm_driver' => $report['llm_driver'],
        ]);
    }
}

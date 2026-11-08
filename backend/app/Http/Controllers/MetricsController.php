<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Metrics\PrometheusMetrics;
use Illuminate\Http\Response;

class MetricsController extends Controller
{
    public function __invoke(PrometheusMetrics $metrics): Response
    {
        return response($metrics(), headers: ['Content-Type' => PrometheusMetrics::CONTENT_TYPE]);
    }
}

<?php

declare(strict_types=1);

$requested = (string) env('LLM_DRIVER', 'auto');
$apiKey = env('ANTHROPIC_API_KEY');

return [

    /*
    |--------------------------------------------------------------------------
    | LLM driver
    |--------------------------------------------------------------------------
    |
    | "requested" is the raw LLM_DRIVER value (auto|claude|fake). "driver" is
    | the resolved value the container binds: with "auto", a missing
    | ANTHROPIC_API_KEY selects the offline fake client (K9).
    |
    */

    'requested' => $requested,

    'driver' => $requested === 'auto'
        ? (filled($apiKey) ? 'claude' : 'fake')
        : $requested,

    'anthropic' => [
        'api_key' => $apiKey,
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 2048),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

    // Validation failures are fed back to the model at most this many times (K11).
    'max_repair_attempts' => 2,

];
